---
title: "ADR 0266: Sleep and wake containers through a signed-order executor"
sidebarTitle: "0266 Signed-order executor"
description: "In progress. A long-running Rust executor on each app Node sleeps and wakes a whole container in-process. It acts only on Ed25519 orders that the Gateway signs and that reach it through the observer's WebSocket. Swamp phases on the Node hand over orders through one fixed relay command. A wake runs inside the visitor's request. Replaces the Python helper and fast-wake files of ADR 0261."
---

# ADR 0266: Sleep and wake containers through a signed-order executor

A new Rust binary, the **executor**, sleeps and wakes app containers on its Node. It is long-running and multi-threaded, and it does the work itself. It acts only on orders that the Gateway signs with Ed25519, and only when the observer (`orbit-agent.service`) or one fixed relay command hands them over. Sleep and wake orders and their results travel only over the observer's existing WebSocket. The relay command is the local caller for Swamp phases on the Node, such as start and deploy. A wake runs inside the visitor's request: the Gateway waits a short bound for the result and then sends the original request to the app. The Python power helper, its path unit, its dispatcher and the `hibernation.marker` and `hibernation.times` file flow of [ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes) go away. Every sleep and wake stays a durable Orbit Operation.

## Status

In progress.

Slices 1 to 3 are built ([Executor](/reference/executor)): the executor with the no-op `proof` action, its install, the Gateway signer, the `executor-order` event, the observer's pass-through, and the Gateway's result listener with its Valkey hand-off. Slice 2 puts the Operation id, as a numeric string, beside the order in the event: `{"operation", "order"}`. The observer copies it unread into each reply line, `{"operation", "line"}`, because the `accepted` line and a refusal before the payload is read carry no Operation. Every order belongs to an Operation with a `node_id`, and the listener accepts evidence only from that Node. The operator diagnostic sends one fixed `proof.noop` and requires an observed `accepted` followed by `done` on the Operation list.

Slices 4 and 5 are built and have swap-backed proof from real clones with T3 and Vite: 30 signed Rust sleeps and wakes, a three-clone burst and SIGKILL recovery. Every wake returned `done`, with zero failed pages after capture excluded Linux guard markers and restore continued after failed pages. Thirty Python sleeps skipped capture as `placement_unproven`, so they do not measure the same work. [Executor](/reference/executor#rust-sleep-and-discard) records the numbers, scopes and nested-fleet capability limit. No production sleep or wake goes through the executor yet.

2026-11-19: the Gateway's SSH relay fallback and the Swamp recovery of a lost receipt are removed. The WebSocket is the only delivery for a signed sleep or wake. The relay command stays only as the local caller for the Swamp start, deploy and stopped-container boot phases. [The path](#the-path) and [Delivery and failure](#delivery-and-failure) describe the result.

Principle: [One way, one name](/mission#principles), [Deterministic first](/mission#principles) and [Security fits the real threat model](/mission#principles). One executor does every sleep and wake for every caller. The kernel's page records decide what comes back. The signature is the security boundary, and root on a Node is an accepted risk. Exceptions:

- [No exceptions and no legacy](/mission#principles): the wake keeps the narrow exception of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) step 8. On success, no Swamp run executes. The executor, not a role script, now does the work.
- The frozen agent of [ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained): the observer gets one new event, which it passes on unread. It gets no new command and no new fact.

## Context

Today a wake takes 0.72 s on the Node with the live Python helper: restore 0.39–0.45 s, Incus reads 0.12–0.23 s, start about 0.15 s. A visit reaches the app 1.5 s after it arrives when the Node is warm and 2.4 s when it is cold, behind a waiting page. The Gateway reaches the Node through SSH and files in `/dev/shm`. A path unit, a dispatcher, a systemd template worker and a Python program each add their own start-up time.

**Evidence.** Spike group 5552 built this design on a nested fleet in four rounds. Round 1 used a socket-activated executor that started a systemd unit. Round 2 used a long-running executor that acts itself. Round 3 added whole-subtree sleep and wake in Rust. Round 4 measured sleep and wake with real swap. The full results, raw data and code are in commit `af8f00e4848f27883beba6221a652cd9fa9f2f6e` on the `task-5552` branch, under `spike/signed-orders/`. That folder is not part of this record, so the numbers that matter are copied here. In round 1, the unit started 94 ms median after the broadcast, against 61 ms over a shared SSH connection and 195 ms over a fresh one. Rounds 2–4 gave:

| Measure (rounds 2–4) | Result |
| --- | --- |
| Gateway broadcast → order at the observer | 3.9–6 ms median |
| Observer → executor, caller and order checks pass | under 1 ms more |
| Rust wake of an 872 MB, 7-process stand-in from swap, 32 restore threads | 176–251 ms median to the target answering HTTP |
| Result in the waiting Gateway request after the target answers | about 2 ms |
| Rust sleep, whole subtree, to swap | 282 ms median, of which reclaim 233 ms; 662 MB → 10 MB in RAM |
| Restore pool size, single wake | 2 threads 1,619 ms; 16 threads 254 ms; 32 threads 176 ms; 64 threads 193 ms |
| Three wakes at once on one pool of 32 | last answers at 747 ms median |
| Caller security tests: another user, root outside the unit, a look-alike unit, the relay user running another program | every one refused and logged |
| Order security tests: bad signature, changed action or target, expired, replayed (also across a restart), unknown action, wrong Node | every one refused and logged |
| SIGKILL in the middle of a wake | systemd restarted the executor in 0.34 s; it finished the open wake from its journal; a repeat of the Operation returned that result |

The spike used a delegated cgroup and a 7-process stand-in. Subtask 5768 subsequently measured the real Incus API and real clones with T3 and Vite on the nested Instance host. Its nested-fleet capability and Python capture limits are recorded in [Executor](/reference/executor#rust-wake).

## Decision

The owner's decisions D1–D8 bind every part of this design. The sections after them set the order, the path, the executor's rules, the wake inside the request, delivery and failure, and the slices.

### The owner's decisions

- **D1. A Rust executor does the work.** A separate binary, `orbit-executor`, built from the `apps/agent` Cargo workspace, sleeps and wakes containers itself: in-process, multi-threaded and long-running. No systemd call, Python program, dispatch script or path unit sits on the path. systemd only starts it at boot and restarts it (`Restart=always`). Only roles that need it install it: `app-dev` now, and `app-prod` when ADR 0265 (group 5556) brings sleep to production.
- **D2. The whole container hibernates.** A sleep freezes and records every process in the container's cgroup subtree, with no picking: T3 and Vite included. The plan records each readable page that is present **or swapped** (pagemap bit 63 or bit 62). Guard markers (bit 58) contain no readable page and are excluded. A partial wake therefore never shrinks the next sleep's plan.
- **D3. Every millisecond counts.** The wake runs inside the visitor's request. Caddy sends the visit to the Gateway, the Gateway waits a short bound for the wake, and then the original request goes to the app. The visitor waits for the wake; a failure or a wake longer than 30 s gets a plain `503` text, never a waiting page (2026-11-19).
- **D4. No Node-to-Node traffic, ever.** Everything flows through the Gateway, which authorizes and signs.
- **D5. Orders and results travel over the observer's WebSocket** (Reverb), not SSH or HTTP POSTs. A long-running Gateway listener hands each result to the waiting request by Operation id through Valkey.
- **D6. The observer only passes bytes.** It stays the only networked part. The executor accepts a connection only from the observer or from the one fixed relay command that Swamp phases run on the Node, and it acts only on Gateway-signed Ed25519 orders.
- **D7. Every wake and sleep stays a durable Orbit Operation.**
- **D8. Idle sleep covers every app container**, clones with T3 included. It has been live since 2026-11-16 14:10Z with `ORBIT_HIBERNATION_CONTAINER_SLEEP=true`. Production follows through ADR 0265 (group 5556).

### The order

The Gateway signs one JSON payload with an Ed25519 key that only the Gateway holds. The payload holds:

| Field | Meaning |
| --- | --- |
| `action` | `sleep`, `wake` or `discard`. Nothing else is allowed |
| `node` | The Node id |
| `target` | The Instance id |
| `operation` | The Operation id |
| `nonce` | 128 random bits, hex |
| `expires_ms` | Gateway time plus 5 s |

The wire form is `base64(payload).base64(signature)`, at most 4 KB. The executor verifies with `verify_strict` against a pinned public key. It then checks, in this order: the action, the Node, the target, the expiry, the nonce and the Operation id. Each refusal has its own reason, goes to the journal, and comes back to the Gateway as a `refused` result. The Gateway logs refusals and alerts on them.

**Expiry and clock skew.** The executor refuses an order whose expiry is past, or more than 10 s ahead of its own clock. So a Node clock may run up to 5 s fast or 5 s slow before every order is refused. A refusal reports the Node's clock. Doctor reports a Node whose clock differs from the Gateway's by more than 1 s. Skew is not measured: the nested fleet shares one kernel clock.

**Key custody.** The secret key sits in the Gateway's secret store, readable by `orbit`. Any process that runs as `orbit` on the Gateway already reaches every Node with sudo over SSH, so one narrower signer process would protect nothing more. Each Node pins up to two public keys in `/etc/orbit/executor/gateway.pub`, which the role convergence writes. Two keys allow a rotation without a gap.

**Target truth.** The executor accepts a target only when the Instance's container exists in the Node's Incus. It reads this through the Incus local socket, which the observer cannot write. There is no target file.

### The path

1. **Gateway.** It records the `instance.wake` or `instance.sleep` Operation under the Instance lock. Then it signs the order and publishes it as the server event `executor-order` on `presence-node.{id}`. Only the Gateway can publish server events, and it signs membership only for `agent.{id}` and itself. A second channel would add an authorization path and save nothing.
2. **Observer.** It receives the event and writes the bytes unchanged to `/run/orbit-executor.sock`. It cannot sign and does not parse the order. It caps the order at 4 KB, keeps at most 64 orders in flight, and reads each reply within 10 s and 64 KB. It sends each reply line as the client event `client-executor-result` on the same channel. Orders that arrive while it reconnects are lost: the Operation fails, as [Delivery and failure](#delivery-and-failure) describes.
3. **Executor.** It accepts a connection only from:
   - root whose cgroup is exactly `0::/system.slice/orbit-agent.service` (the observer), or
   - the user `orbit-executor-relay` whose executable is exactly the executor binary (the relay, which Swamp phases run on the Node).

   It checks the caller through `SO_PEERCRED` and `SO_PEERPIDFD`. It reads the cgroup and the executable for that PID, then sends signal 0 to the pidfd: a live pidfd proves the PID was not reused during the check. The socket has mode `0660 root:orbit-executor-relay`, so the kernel refuses every other user first. The executor writes an `accepted` line at once, then the result line.
4. **Listener.** The existing agent view subscriber (`orbit-agent-view.service`) is the long-running listener. It accepts a result only when Reverb stamps it with the member `agent.{id}` of that Node's channel. It checks that the sender owns the Operation before it queues either reply line. It records the terminal result on the Operation (D7) before pushing it to the same ordered Valkey list `executor:op:<operation>`, with a 60 s expiry.
5. **Waiting request.** It does `BLPOP executor:op:<operation>` within its bound.

### The executor

- **Per-target lock.** Each Instance has its own lock. Orders for one Instance run one at a time, and different Instances run in parallel. The only shared state is the nonce map, held for one insert, with no I/O under it.
- **Idempotency by Operation id.** A new order for an Operation that is already done returns the first result, marked `repeat`, and does not act. An order for an Operation that is running waits for the target lock and then returns that result. A replayed nonce is refused, and the refusal carries the first result once it exists.
- **Journal.** It is append-only, at `/var/lib/orbit/executor/journal`. It gets one `write` when the executor accepts an order, before the order waits for its target lock (a `seen` record), one before the action (`open`: nonce, Operation, target, action) and one after it (`done`: the result). An order that expired while it waited for the lock gets a `done` record with `executor.order_expired` and never acts. No `fsync` sits on the path. A background thread syncs the journal once a second. A crash of the executor keeps every written record in the page cache. After a Node reboot every order has expired, and the containers' processes are gone. The executor keeps done results for 24 hours and compacts the journal while it runs.
- **Crash recovery at start-up.** The executor reloads the nonces that have not expired and the done results. An order accepted without an `open` record never acted: a crash ended its wait for the lock, so it gets a `done` record with `executor.order_expired`. The executor finishes every open order without a result: a wake runs again, which is safe because the identity check and the restore can repeat. A sleep runs again and keeps a plan that is already in place. The executor pushes nothing by itself, because it has no outbound path. The Gateway learns the result only when it sends an order for the same Operation again.
- **One restore pool per Node.** About 32 threads, sized by the swap device's queue depth, not by CPU count. The role writes the size, and the floor is 16. The pool serves one target's items fully before the next target's. In a burst, the first wake finishes in about the time of a single wake, and the last finishes when it does today. The pool is separate from the executor's async workers, so order checks never wait behind a restore.
- **Sleep.** It records the subtree's swap limits that are not `max` and opens them. It freezes the container through the Incus local API and lists `cgroup.procs` through the whole subtree. It scans maps and pagemap in parallel and publishes the plan. Then it reclaims until `memory.current` settles or 2 s pass. The record and the plan are published atomically with `fsync`, because a sleep is not on a visitor's path. The executor writes the asleep marker. The swap-full stop rule and the 32 MiB residual-anonymous policy of [Prepared restore](/reference/prepared-restore) move into the executor unchanged.
- **Wake.** Before any memory syscall, it checks the boot id, the cgroup inode, the Incus incarnation and every process's start time through a pidfd. It restores through the pool with `process_madvise(MADV_WILLNEED)` and `process_vm_readv`, within 2 s, and skips pages that are already resident. Then it unfreezes through the Incus local API and closes exactly the recorded swap limits. It removes the asleep marker and runs the Instance's readiness check. A restore cut short reports `partial`, and the wake goes on.
- **Discard.** It drops a plan and its limits record after a stop, a destroy or a deploy that boots fresh.
- **Incus memory limits.** A change to an Instance's `limits.memory` through Incus rewrites its `memory.swap.max`. Every path that changes memory limits on a sleeping Instance wakes it through the executor first.

### The wake inside the request

1. Caddy on the Node finds the asleep marker and calls the Gateway's [activation endpoint](/reference/app-dev-runtime-hibernation), as today.
2. The Gateway records or joins the wake Operation, signs, publishes, and holds the visit while it waits for the result. The hold lasts up to 30 s, the Gateway setting `ORBIT_VISIT_WAKE_WAIT_SECONDS`.
3. On `done` or `partial`, the endpoint answers `307` to the visitor's own path and query. Caddy returns it unchanged, the browser repeats the request with the same method and body, and the awake app answers it. A `200` would end the request as an empty page on routes that keep the activation and the app in separate `handle` blocks, as production's published routes do.
4. On a failed or refused result, an ended Operation or the end of the hold, the endpoint answers `503` with one plain sentence: no page, no script and no reload. The Operation goes on as below. A reload of the page is a new visit.

2026-11-19 (Nick, after signed sleep and wake went live): the visit is held; the progress page is removed. A signed wake returns the real page in about 0.4 s. This supersedes the 2026-11-15 rule "wake spinner shows at once; never hold the request" and the first version of this section, which answered a progress page after a 500 ms bound. A container that the Python path froze resumes in 14–19 s until its next executor sleep; the 30 s hold covers it, so it needs no state of its own.

### Delivery and failure

The WebSocket is the only delivery for a signed sleep or wake. The Gateway publishes the order once and waits for the result. An explicit signed wake queues delivery and follows its Operation, like Conn. Only the visitor's request has the 30 s hold.

- **A terminal result arrives.** Once the result is durable, the listener queues completion at once. The worker projects the result and sends the Operation's notices without waiting for the sweep. A completion job 6 s after the publish still covers a missed handoff.
- **No result by the 6 s completion deadline.** This covers a publish that threw, an order with no `accepted` line, and an `accepted` order whose result was lost. None of them proves what the Node did: a publish can deliver and then throw, and a lost listener misses both lines. So the Gateway reconciles by the journal, below. It never frees the lock on silence alone.
- **A wake receipt is `failed` with `instance.container_stopped`.** The container has no memory to resume. The Operation starts the Swamp wake run, which boots it. This is the only Swamp run after a signed wake.

### Reconcile by the journal

2026-11-19 (review of the relay removal): the executor writes a `seen` record to its journal when it accepts an order, before the order waits for its target lock, an `open` record before it acts and a `done` record after. After it gets the lock it checks the expiry again: an order that expired while it waited ends `done` with `executor.order_expired` and never acts. So a pending order always shows a record, and no record after expiry means the Node never received the order. The order expires 5 s after signing, and the Node refuses an expired order before it reads its journal, so re-sending the order cannot recover its result. Reading the journal can. When no result arrives by the deadline, the Gateway runs one read-only SSH command on the Node: it prints the Node's clock and the journal lines for the Operation, action and target. It sends no order and cannot act.

- **A `done` record.** That is the order's result. The Gateway records it like a WebSocket result, with `delivery: journal`, and completes the Operation through the same code as a result on time, even after the Operation ended `interrupted`: the same status, power state and boot handoff. The lock goes with it.
- **No record, and the Node's clock is past the order's expiry.** The Node never acted and never will. The Operation fails and frees its lock: `executor.publish_failed` when the publish threw, otherwise `executor.not_accepted`.
- **A `seen` or `open` record without `done`, or the Node's clock is not yet past expiry.** The action may still run. The Operation keeps running, and the Gateway reads again after 1 s, so a held visitor sees a slow result within its deadline. After 30 s it reads every 10 s.
- **The Node cannot be read, or its journal cannot prove absence.** R2 applies: the Operation ends `interrupted` with `executor.result_unknown` and keeps its lock. The reconcile pass queues a new read every 10 s, and the answer above settles the Operation and frees the lock. Each read is its own job on the `operations` queue with a 2 s connect timeout, so one unreachable Node delays neither another Instance's recovery nor a wake's completion. The read job is unique per Operation: while one read waits or runs, the pass queues no other, so an outage cannot pile reads onto the queue. An executor that predates the `seen` record writes no `journal.accepts` marker beside the journal, and it may run an order that it holds after the order's expiry. Its journal proves absence only for an order that expired before the running executor process started: the process that held the order is gone, and a new one refuses it as expired. The read reports that start time with the clock. So an Operation stranded under an old executor settles at the first read after the rollout of a new build restarts the executor. No journal proves absence for an order older than the 24 hours the executor keeps done records; such an Operation stays fenced until a record decides it or an operator does.

2026-11-22: the same read covers an `instance.start` or `instance.deploy` that ended `interrupted` under the fence of a lifecycle order its Swamp run had signed (a boot `discard` or a resume `wake`). Its run is over, so nothing else can settle it. The pass reads the journal for the action its admitted phase signs, bounded by the Operation's start and end because no publish time exists. A `done` record, or a late WebSocket receipt, is recorded once (R1) and frees the lock; the Operation keeps `interrupted` and `executor.result_unknown`, since its run's result stays unknown, and its state names what settled it under `executor_fence_settled`. No record after expiry frees it as well. An unreadable Node keeps the fence.

This lookup is the one Swamp-free recovery for a lost result. It replaces the deleted relay re-send and the Swamp sleep recovery.

A visitor's held request sees a failed Operation and gets the plain `503`.

**The relay command.** Swamp start, deploy and the stopped-container boot resume a signed freeze, discard it, or boot through the executor on the Node. Their machine phase asks the Gateway to sign a fresh order for its Operation, then runs `sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` with the order on stdin. The relay copies the order to the socket and the reply to stdout. The action, Node and target come only from the committed admission, never from the request. The signer accepts only the authorized workflow identity, its bound run and an active, uncancelled Operation. Only the phase sees the order; it never goes to run inputs, arguments or output. Swamp holds no signing key. The executor returns the stored result or acts once.

Engine choice stays on the Operation after admission, including across a setting change. The signed engine is off by default. A frozen Instance wakes through the engine that froze it whatever the settings say (R3), so a recorded freeze needs no drain in either direction: turning the settings off is the whole rollback. A freeze with no recorded owner is woken with the settings off before the switch. Only before retiring the executor from a Node, wake the Instances that still have a Rust sleep plan. Do not hand a frozen Rust plan to Python or a Python plan to Rust. A stopped container has no resumable memory; its ordinary lifecycle may boot it after the applicable guard and cleanup.

A start or deploy admitted to resume a signed freeze requires a matching executor receipt. An already-running container that needs no resume or discard can still take its usual lifecycle path without a receipt.

So every sleep and wake, whatever the caller (WebSocket, Swamp run, deploy, start), goes through one executor with one journal. Destroy sends no signed order yet: it removes both engines' files as plain cleanup after the container is gone. A signed destroy is a follow-up, Orbit task 5891.

### Four rules for every signed outcome

Each rule has one place in the Gateway that enforces it. Every caller goes through that place; no caller decides the case again.

1. **The receipt decides the outcome (R1).** The durable, matching executor receipt is the only source of truth for what happened to the container. A transport outcome (WebSocket, relay, Swamp, Valkey, a refused start, a timeout) never overrides a recorded receipt. It only decides whether to ask again. A run's own failure stands beside a successful receipt only when the run does work after the executor, as a start or deploy wrapper does. Projection reads the receipt's verdict, `done`, `partial`, `stopped`, `refused` or `failed` with its code, for sleep, wake, start and deploy alike. A `failed` wake with `instance.container_stopped` is no outcome of its own: it hands the container to the ordinary boot, and the boot's own result (`wake: started`, then readiness) decides. Every end of an Operation, a late receipt included, takes this verdict in `ExecutorFence::settle()`, which `ExecutorFence::verdict()` decides.
2. **An issued order keeps its fence (R2).** Once the Gateway signs an order for an Operation (published or handed to a Swamp phase), the order may reach the Node. From then on the Instance stays locked and the Operation cannot be cancelled until a terminal receipt exists. With no receipt, the end is `interrupted` with `executor.result_unknown`, and the lock stays; nothing calls that released. Only the Node's journal lifts the fence without a receipt: it shows no record of an expired signed order ([Reconcile by the journal](#reconcile-by-the-journal)), so a sleep or wake fails with `executor.not_accepted` or `executor.publish_failed`, a start or deploy keeps its `interrupted` end, and the lock goes. A missing receipt for an issued order is never a success. An Operation that issued nothing yet can still be cancelled, and the cancel settles it at once. Enforced by `ExecutorFence`.
3. **The freezing engine owns the freeze (R3).** The engine whose sleep froze the container owns it until a receipt from that engine says it is awake, stopped or discarded. Settings, sleeps after the freeze that froze nothing (cancelled, refused, failed with no freeze) and admission decisions never move that ownership. The ownership ends with a receipt that the Node discarded the freeze, even when the ordinary boot after it fails. Wake, start and deploy resume through the owner. Destroy removes both engines' files after it deletes the container, so a target frozen by either engine leaves nothing behind. Enforced by `ContainerResume::frozenSleep()`.
4. **A stuck end is a failure with a retry (R4).** A terminal wake that cannot advance, `failed` or `interrupted`, is shown as failed with its code, never as pending: a visit gets a plain `503` that names the code, and a reload admits a new wake. A visit while the fence of rule 2 holds gets the same failure; it cannot admit a second wake beside an unknown order. The same rule covers a nested clone's start that stands in for its wake, and a wake that ended before its visit returned.

The visitor's request bound is part of the wake, not of the current settings: a wake that resumes a signed freeze bounds its admission write even after the signed engine is switched off.

### Must clear before the signed power flags go on

`ORBIT_EXECUTOR_WAKE_ENABLED` and `ORBIT_EXECUTOR_POWER_ENABLED` stay off until each item here is cleared. Open blockers from review: none.

- **Cleared.** Every app-dev Node runs the same executor build as the Gateway expects: the pin moved to `cli-v0.10774.0`, the delivery built from #4032's commit, so the next Gateway release's fleet rollout installs that build on every `app-dev` Node. [`orbit:executor-parity`](/reference/executor#build-parity) compares each Node's executor SHA-256 with the pin and names every Node that differs. The switch runs it first and changes no flag while it fails.
- **Cleared.** Every Instance already asleep wakes through the engine that froze it, so the switch needs no drain. R3 already records the owner on the freezing sleep, and every wake entry point reads it before the settings: `instance:wake` and its API, the visitor's activation, Conn, and a start or deploy that resumes. The idle sweep only sleeps, so its next sleep takes the new setting. A failed Python sleep can leave a container frozen with no recorded owner. `orbit:executor-parity` fails with `instance.frozen_unrecorded` and names each such Instance. So the switch runs the check, wakes each listed Instance with the settings still off, and changes the settings only once the check passes. Subtask 5911 proved both directions and the check on the nested fleet ([Switching engines](/reference/executor#switching-engines)).
- **Cleared.** The activation proxy on every `app-dev` Node reaches this Gateway with the method the route renderer draws. The executor's Caddy guard checks only the first handler and its rewrite URI. So `orbit:executor-parity` also reads each Node's loaded Caddy config in the same read-only SSH call. It finds each site's activation proxy for each Instance by the asleep-marker route, as the guard does. It compares the rewrite URI, the upstream, the method, the `Host` header and the TLS server name with what `DevelopmentCaddyConfigRenderer` draws for that Instance. It also checks that the upstream resolves on the Node only to this Gateway's WireGuard address. Any difference fails with `caddy.activation_proxy_wrong`, which names the Node, the Instance, the site and the field. Subtask 5912 proved a pass, a wrong method, a wrong upstream, a removed rewrite and a broken second site or second listener on the nested fleet ([Activation proxy](/reference/executor#activation-proxy)).

All three items are cleared. The switch goes in this order ([Switching engines](/reference/executor#switching-engines)):

1. With both settings off, run `orbit:executor-parity`.
2. Wake each Instance it lists, and fix every other problem it names.
3. Run it again. When it passes, turn on `ORBIT_EXECUTOR_WAKE_ENABLED` and `ORBIT_EXECUTOR_POWER_ENABLED`.

The rollback is one step: turn both settings off. Instances that the executor froze still wake through it.

### What this supersedes and changes

- **[ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes).** These are replaced by the executor: the Python engine, its five commands and its transient-unit lock; the path unit, the dispatcher and the `orbit-instance-wake@` worker; the `.wake`, `.taken`, `.ready-*`, `.done` and `.failed` files; and the Gateway's use of `hibernation.marker` and `hibernation.times` for the wake. The counter settle moves from Ansible into the executor. Three of its rules stay: the generic plan, restore before the unfreeze, and a restore as a hint. Two change: the plan now records swapped pages too (D2), and "the HTTP activation path is out of scope" ends (D3).
- **[ADR 0225](/decisions/overview#retired-decisions)**, absorbed into [Node agent: Commands](/reference/node-agent#commands). Typed commands over SSH stay the only way to change a Node, **except** sleep and wake. These are signed orders to the executor.
- **The boundary "the Gateway is the center, Swamp does the work, orbit-agent only observes."** The Gateway stays the center and the only authority. The observer still only observes and passes bytes. The executor acts, for sleep and wake only. Swamp's sleep and wake workflows call it and do no memory work themselves.
- **The frozen agent ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)).** One observer release adds the pass-through. The executor ships in the same pinned delivery as the agent.
- **[App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).** The activation endpoint holds a container Instance's visit until its wake ends, up to 30 s. The progress page is removed (2026-11-19).
- **Pages that change:** [Prepared restore](/reference/prepared-restore), [Node agent](/reference/node-agent), [Development containers: Sleep and wake](/reference/development-containers#sleep-and-wake) and [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation). Each carries a target-design note until the slices land.

### Slices

Each slice is one small pull request. It starts by updating the [sleep](/cli/instance/sleep) or [wake](/cli/instance/wake) chart, where its flow changes. Its proof runs on a real clone with T3 and Vite.

| # | Slice | Proof | Needs 5513 |
| --- | --- | --- | --- |
| 1 | Executor skeleton and install: crate in `apps/agent`, unit, socket, relay user, pinned keys, journal, caller checks, the `app-dev` role install, the release asset | Caller security tests on an `app-dev` Node; restart and journal reload | No |
| 2 | Signed orders and the observer pass-through: Gateway signer, `executor-order` event, observer release, order checks with a no-op action | Order security tests; 50 orders, latency to the executor | No |
| 3 | Gateway listener and Valkey hand-off: result on the Operation, `executor:op:<id>`, refusals logged | 50 round trips; result in a waiting request; a lost result recovered by a repeat | No |
| 4 | Rust sleep: limits, Incus freeze, whole-subtree scan with bit 62, plan, reclaim, residual policy, asleep marker | 30 sleeps of a real clone with T3 and Vite; RAM and swap after each | **Yes** |
| 5 | Rust wake with the pool: identity, pool of about 32 serving one target at a time, Incus unfreeze, limits, readiness, crash recovery | 30 wakes and a burst of 3 on a real clone; the Incus unfreeze cost; a SIGKILL test | **Yes** |
| 6 | The wake inside the request: Gateway bound, Caddy sends the original request on, plain `503` otherwise | 50 visits to a sleeping clone, time to the app's first byte, against today's 1.5 s and 2.4 s | **Yes** |
| 7 | Swamp calls the executor through the relay on the Node: deploy, start and the boot of a stopped container; destroy only removes both engines' files (signed destroy: task 5891) | WebSocket path down: the Operation fails `executor.not_accepted`; a start that resumes a signed freeze; a crossed order runs once | **Yes** |
| 8 | Remove the Python path: the helper, path unit, dispatcher, worker template, file flow, Ansible counter settle; absorb this ADR | The sleep and wake proofs of slices 4–7 still pass; nothing installs the old units | **Yes** |

Slices 1–3 can run before group 5513 lands. Slices 4–8 need a nested `app-dev` Node that hosts real app containers.

## Rejected alternatives

- **Socket activation with one process per connection, starting a systemd unit** (round 1): about 45 ms for the spawn and 40 ms for `systemctl start`. That is slower than a warm shared SSH connection.
- **Results by HTTP POST with the agent secret:** about 25 ms slower than the channel, with a longer tail and a Laravel boot for each result.
- **Keeping the Python helper behind a faster trigger:** its start-up time, Incus reads and file flow cost 0.27–0.38 s that the executor removes.
- **A restore pool per wake, or one sized by CPU count:** 5 × 32 threads starved the order checks. 2 threads restored 9 times slower than 32, because swap-in waits on disk.
- **Recording only resident pages:** a partial wake then shrank every following plan, by 30k pages in round 4.
- **A second private channel for orders:** it adds an authorization path, and the presence channel already admits only the Node's agent and the Gateway.
- **`fsync` on the order's path:** three syncs were 12 of 20 ms in round 2. The page cache already survives an executor crash, and a reboot expires every order.
- **SSH as the primary path:** a fresh connection costs 132 ms, and every call is one more process start on the Node.

## Consequences

- A visit to a sleeping app is answered by the app itself, in the same request, when the wake fits the bound. The spike's stand-in woke in 0.18–0.25 s against 0.72 s today.
- One implementation serves every caller, so the fast path and the run cannot differ.
- A new root-run program acts on Nodes. Its whole input is a signed order. Root on a Node can impersonate the observer, but it cannot forge or replay an order. It can only drop orders, and a dropped order fails its Operation. This is an accepted risk.
- A wake restores swapped pages as well as resident ones. This costs more swap I/O at the wake than ADR 0261's plan, and it gives a full container back.
- The executor needs its own release asset, built in CI. A task copy cannot change `.github/workflows/`, so slice 1's workflow change goes through a maintainer pull request.
- The bound and the pool size are starting values. Slices 4–6 measure them on a real clone.
- A WebSocket outage fails signed sleeps and wakes instead of falling back to SSH. A visitor sees the plain `503`, and a reload admits a new wake.

### Open points

1. **The 500 ms wait is a starting value.** It must be measured on a real clone with T3 and Vite (slice 6). The same holds for the pool size.
2. **The effect of recording swapped pages on wake time is unmeasured.** A container whose memory was in swap long before the sleep now restores all of it (D2).
3. **The relay is a second allowed caller beside the observer.** The user `orbit-executor-relay`, running the executor binary, may connect to the socket. It serves the Swamp start, deploy and boot phases on the Node. It still needs a signed order, but it widens the set of callers that D6 started from.
4. **Clock skew is untested.** The nested fleet shares one kernel clock. Skew over 5 s refuses every order on that Node until it is fixed.
5. **The signing key stays readable by the Gateway's `orbit` user.** See Key custody: a separate signer would protect nothing more today, but any `orbit` process can sign orders.
6. **A result finished after an executor crash reaches the Gateway only when it asks again.** The executor has no outbound path. The Gateway learns the result only by sending the same Operation again, which signed sleep and wake do not do: such an Operation ends `interrupted` with `executor.result_unknown` and keeps its lock (R2).
7. **Incus unfreeze is measured.** Its state-change request and operation wait took p50/p95/max 9.471/18.734/19.041 ms over 30 wakes. Freeze is included in the measured sleep total but was not timed separately.
8. **Real clones with T3 and Vite are proven.** All 30 single wakes, the burst and SIGKILL recovery returned `done` with zero failed pages. Linux guard markers are excluded at capture; short reads continue after each failed page. The nested fleet gets `EPERM` for the optional `process_madvise` hint, so its timings have no WILLNEED head start. The effect of pages cold for hours remains unmeasured.
9. **Burst queue time counts toward the bound.** The three clones woke on the Node in 238.297, 402.372 and 484.466 ms. Larger bursts and a different swap device remain unmeasured.
10. **Root on the Node is trusted**, as above.

## Affects

- Components: apps/gateway, apps/docs
- Rust: the new executor crate and the observer pass-through in the `apps/agent` workspace
- ADRs: replaces most of [0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes); extends the fast-wake exception of [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) step 8; makes one exception to the frozen agent of [0253](/decisions/0253-patch-nodes-and-reboot-them-drained); 0265 (group 5556) brings it to `app-prod`
- Swamp: the sleep and wake workflows and the `app-dev` role under `swamp/`
- Detail: [Prepared restore](/reference/prepared-restore), [Node agent: Commands](/reference/node-agent#commands) and [How it connects](/reference/node-agent#how-it-connects), [Development containers: Sleep and wake](/reference/development-containers#sleep-and-wake), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation)
- Verify: the executor's Rust tests (order checks, caller checks, journal recovery, pool order); Gateway feature tests for the signer, listener, publish failure and expiry without `accepted`; `bin/swamp-shape-gate`; the proofs in the slice table
