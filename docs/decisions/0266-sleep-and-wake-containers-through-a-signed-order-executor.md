---
title: "ADR 0266: Sleep and wake containers through a signed-order executor"
sidebarTitle: "0266 Signed-order executor"
description: "In progress. A long-running Rust executor on each app Node sleeps and wakes a whole container in-process. It acts only on Ed25519 orders that the Gateway signs and that reach it through the observer's WebSocket, or through one fixed SSH relay command. A wake runs inside the visitor's request. Replaces the Python helper and fast-wake files of ADR 0261."
---

# ADR 0266: Sleep and wake containers through a signed-order executor

A new Rust binary, the **executor**, sleeps and wakes app containers on its Node. It is long-running and multi-threaded, and it does the work itself. It acts only on orders that the Gateway signs with Ed25519, and only when the observer (`orbit-agent.service`) or one fixed relay command hands them over. Orders and results travel over the observer's existing WebSocket. A wake runs inside the visitor's request: the Gateway waits a short bound for the result and then sends the original request to the app. The Python power helper, its path unit, its dispatcher and the `hibernation.marker` and `hibernation.times` file flow of [ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes) go away. Every sleep and wake stays a durable Orbit Operation.

## Status

In progress.

The owner decided D1–D8 below on 2026-11-16. Slices 1 to 3 are built ([Executor](/reference/executor)): the executor with the no-op `proof` action, its install, the Gateway signer, the `executor-order` event, the observer's pass-through, and the Gateway's result listener with its Valkey hand-off. Slice 2 puts the Operation id, as a numeric string, beside the order in the event: `{"operation", "order"}`. The observer copies it unread into each reply line, `{"operation", "line"}`, because the `accepted` line and a refusal before the payload is read carry no Operation. Every order belongs to an Operation with a `node_id`, and the listener records a result only from that Node. Slice 4 is in development: Rust sleep/discard and default-Instance measurements do not complete the required real-clone proof. Wake is not built yet.

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

Two things are not yet proven. The spike froze and thawed a delegated cgroup directly, not through the Incus unfreeze call. Its stand-in was a 7-process cgroup, not a real clone with T3 and Vite. Both wait for group 5513, which makes nested-app-dev host app containers.

## Decision

The owner's decisions D1–D8 bind every part of this design. The sections after them set the order, the path, the executor's rules, the wake inside the request, the fallback and the slices.

### The owner's decisions

- **D1. A Rust executor does the work.** A separate binary, `orbit-executor`, built from the `apps/agent` Cargo workspace, sleeps and wakes containers itself: in-process, multi-threaded and long-running. No systemd call, Python program, dispatch script or path unit sits on the path. systemd only starts it at boot and restarts it (`Restart=always`). Only roles that need it install it: `app-dev` now, and `app-prod` when ADR 0265 (group 5556) brings sleep to production.
- **D2. The whole container hibernates.** A sleep freezes and records every process in the container's cgroup subtree, with no picking: T3 and Vite included. The plan records each page that is present **or swapped** (pagemap bit 63 or bit 62). A partial wake therefore never shrinks the next sleep's plan.
- **D3. Every millisecond counts.** The wake runs inside the visitor's request. Caddy sends the visit to the Gateway, the Gateway waits a short bound for the wake, and then the original request goes to the app. When the bound passes first, the visitor gets today's waiting page.
- **D4. No Node-to-Node traffic, ever.** Everything flows through the Gateway, which authorizes and signs.
- **D5. Orders and results travel over the observer's WebSocket** (Reverb), not SSH or HTTP POSTs. A long-running Gateway listener hands each result to the waiting request by Operation id through Valkey.
- **D6. The observer only passes bytes.** It stays the only networked part. The executor accepts a connection only from the observer or from the one fixed relay command, and it acts only on Gateway-signed Ed25519 orders.
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
2. **Observer.** It receives the event and writes the bytes unchanged to `/run/orbit-executor.sock`. It cannot sign and does not parse the order. It caps the order at 4 KB, keeps at most 64 orders in flight, and reads each reply within 10 s and 64 KB. It sends each reply line as the client event `client-executor-result` on the same channel. Orders that arrive while it reconnects are lost: the fallback below covers them.
3. **Executor.** It accepts a connection only from:
   - root whose cgroup is exactly `0::/system.slice/orbit-agent.service` (the observer), or
   - the user `orbit-executor-relay` whose executable is exactly the executor binary (the relay).

   It checks the caller through `SO_PEERCRED` and `SO_PEERPIDFD`. It reads the cgroup and the executable for that PID, then sends signal 0 to the pidfd: a live pidfd proves the PID was not reused during the check. The socket has mode `0660 root:orbit-executor-relay`, so the kernel refuses every other user first. The executor writes an `accepted` line at once, then the result line.
4. **Listener.** The existing agent view subscriber (`orbit-agent-view.service`) is the long-running listener. It accepts a result only when Reverb stamps it with the member `agent.{id}` of that Node's channel. It records the result on the Operation, which makes it durable (D7). Then it pushes the result to the Valkey key `executor:op:<operation>`, with a 60 s expiry.
5. **Waiting request.** It does `BLPOP executor:op:<operation>` within its bound.

### The executor

- **Per-target lock.** Each Instance has its own lock. Orders for one Instance run one at a time, and different Instances run in parallel. The only shared state is the nonce map, held for one insert, with no I/O under it.
- **Idempotency by Operation id.** A new order for an Operation that is already done returns the first result, marked `repeat`, and does not act. An order for an Operation that is running waits for the target lock and then returns that result. A replayed nonce is refused, and the refusal carries the first result once it exists.
- **Journal.** It is append-only, at `/var/lib/orbit/executor/journal`. It gets one `write` before the action (nonce, Operation, target, action) and one after it (the result). No `fsync` sits on the path. A background thread syncs the journal once a second. A crash of the executor keeps every written record in the page cache. After a Node reboot every order has expired, and the containers' processes are gone. The executor keeps done results for 24 hours and compacts the journal while it runs.
- **Crash recovery at start-up.** The executor reloads the nonces that have not expired and the done results. It finishes every open order without a result: a wake runs again, which is safe because the identity check and the restore can repeat. A sleep runs again and keeps a plan that is already in place. The executor pushes nothing by itself, because it has no outbound path. The Gateway learns the result by sending the same Operation again (below).
- **One restore pool per Node.** About 32 threads, sized by the swap device's queue depth, not by CPU count. The role writes the size, and the floor is 16. The pool serves one target's items fully before the next target's. In a burst, the first wake finishes in about the time of a single wake, and the last finishes when it does today. The pool is separate from the executor's async workers, so order checks never wait behind a restore.
- **Sleep.** It records the subtree's swap limits that are not `max` and opens them. It freezes the container through the Incus local API and lists `cgroup.procs` through the whole subtree. It scans maps and pagemap in parallel and publishes the plan. Then it reclaims until `memory.current` settles or 2 s pass. The record and the plan are published atomically with `fsync`, because a sleep is not on a visitor's path. The executor writes the asleep marker. The swap-full stop rule and the 32 MiB residual-anonymous policy of [Prepared restore](/reference/prepared-restore) move into the executor unchanged.
- **Wake.** Before any memory syscall, it checks the boot id, the cgroup inode, the Incus incarnation and every process's start time through a pidfd. It restores through the pool with `process_madvise(MADV_WILLNEED)` and `process_vm_readv`, within 2 s, and skips pages that are already resident. Then it unfreezes through the Incus local API and closes exactly the recorded swap limits. It removes the asleep marker and runs the Instance's readiness check. A restore cut short reports `partial`, and the wake goes on.
- **Discard.** It drops a plan and its limits record after a stop, a destroy or a deploy that boots fresh.
- **Incus memory limits.** A change to an Instance's `limits.memory` through Incus rewrites its `memory.swap.max`. Every path that changes memory limits on a sleeping Instance wakes it through the executor first.

### The wake inside the request

1. Caddy on the Node finds the asleep marker and calls the Gateway's [activation endpoint](/reference/app-dev-runtime-hibernation), as today.
2. The Gateway records or joins the wake Operation, signs, publishes, and waits on Valkey within the request bound. The bound starts at 500 ms and is a Gateway setting.
3. On `done` or `partial` within the bound, the endpoint answers 200. Caddy then passes the original request to the app, and the response is the app's own.
4. Otherwise the endpoint answers today's progress page (401, `X-Orbit-Runtime-Activation-State: pending`), and the Operation goes on as below. The page's poll loads the app once the wake ends.

### Fallback: one implementation on both paths

- **No `accepted` line within 150 ms.** The Gateway treats the WebSocket path as down. It runs `sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` over its shared SSH connection, with the same signed order on stdin. The relay copies the order to the socket and the reply to stdout. If both copies arrive, the second is refused as `replayed nonce` and carries the first result. The action runs once.
- **`accepted` but no result within the bound.** The restore is slow, not lost. The Operation keeps waiting for the listener up to 6 s.
- **Still no result.** The Operation starts its Swamp run. Swamp's sleep and wake workflows call the executor through the same relay command. They get a freshly signed order for the same Operation from the Gateway's bound Operation read, because Swamp holds no key. The executor returns the stored result or acts once.

So every sleep and wake, whatever the caller (WebSocket, SSH fallback, Swamp run, deploy, start, destroy), goes through one executor with one journal.

### What this supersedes and changes

- **[ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes).** These are replaced by the executor: the Python engine, its five commands and its transient-unit lock; the path unit, the dispatcher and the `orbit-instance-wake@` worker; the `.wake`, `.taken`, `.ready-*`, `.done` and `.failed` files; and the Gateway's use of `hibernation.marker` and `hibernation.times` for the wake. The counter settle moves from Ansible into the executor. Three of its rules stay: the generic plan, restore before the unfreeze, and a restore as a hint. Two change: the plan now records swapped pages too (D2), and "the HTTP activation path is out of scope" ends (D3).
- **[ADR 0225](/decisions/overview#retired-decisions)**, absorbed into [Node agent: Commands](/reference/node-agent#commands). Typed commands over SSH stay the only way to change a Node, **except** sleep and wake. These are signed orders to the executor.
- **The boundary "the Gateway is the center, Swamp does the work, orbit-agent only observes."** The Gateway stays the center and the only authority. The observer still only observes and passes bytes. The executor acts, for sleep and wake only. Swamp's sleep and wake workflows call it and do no memory work themselves.
- **The frozen agent ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)).** One observer release adds the pass-through. The executor ships in the same pinned delivery as the agent.
- **[App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).** The activation endpoint holds a container Instance's visit for the wake's bound, instead of always answering the progress page at once.
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
| 6 | The wake inside the request: Gateway bound, Caddy sends the original request on, waiting page otherwise | 50 visits to a sleeping clone, time to the app's first byte, against today's 1.5 s and 2.4 s | **Yes** |
| 7 | Swamp and the SSH relay call the executor: fallback on no `accepted`, sleep and wake workflows, deploy, start and destroy | WebSocket path down: a wake through the relay; a run after a lost result; a crossed order runs once | **Yes** |
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
- A new root-run program acts on Nodes. Its whole input is a signed order. Root on a Node can impersonate the observer, but it cannot forge or replay an order. It can only drop orders, and the fallback covers that. This is an accepted risk.
- A wake restores swapped pages as well as resident ones. This costs more swap I/O at the wake than ADR 0261's plan, and it gives a full container back.
- The executor needs its own release asset, built in CI. A task copy cannot change `.github/workflows/`, so slice 1's workflow change goes through a maintainer pull request.
- The bound, the pool size and the 150 ms `accepted` limit are starting values. Slices 4–6 measure them on a real clone.

### Open points

1. **The 500 ms wait is a starting value.** It must be measured on a real clone with T3 and Vite (slice 6). The same holds for the pool size and the 150 ms `accepted` limit.
2. **The effect of recording swapped pages on wake time is unmeasured.** A container whose memory was in swap long before the sleep now restores all of it (D2).
3. **The SSH relay is a second allowed caller beside the observer.** The user `orbit-executor-relay`, running the executor binary, may connect to the socket. It serves the SSH fallback and Swamp. It still needs a signed order, but it widens the set of callers that D6 started from.
4. **Clock skew is untested.** The nested fleet shares one kernel clock. Skew over 5 s refuses every order on that Node until it is fixed.
5. **The signing key stays readable by the Gateway's `orbit` user.** See Key custody: a separate signer would protect nothing more today, but any `orbit` process can sign orders.
6. **A result finished after an executor crash reaches the Gateway only when it asks again.** The executor has no outbound path. The Gateway learns the result by sending the same Operation again.
7. **The Incus unfreeze and freeze calls are not measured.** The spike wrote `cgroup.freeze` directly. If the Incus API adds tens of milliseconds, the bound must grow.
8. **A real clone with T3 and Vite is not measured.** It has more processes, more file-backed pages and pages that have been cold for hours. Swap for the spike was host swap files under load from other Instances.
9. **Bursts exceed the bound.** Three wakes at once ended at 747 ms median for the last. Serving one target at a time helps the first wake only.
10. **Root on the Node is trusted**, as above.

## Affects

- Components: apps/gateway, apps/docs
- Rust: the new executor crate and the observer pass-through in the `apps/agent` workspace
- ADRs: replaces most of [0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes); extends the fast-wake exception of [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) step 8; makes one exception to the frozen agent of [0253](/decisions/0253-patch-nodes-and-reboot-them-drained); 0265 (group 5556) brings it to `app-prod`
- Swamp: the sleep and wake workflows and the `app-dev` role under `swamp/`
- Detail: [Prepared restore](/reference/prepared-restore), [Node agent: Commands](/reference/node-agent#commands) and [How it connects](/reference/node-agent#how-it-connects), [Development containers: Sleep and wake](/reference/development-containers#sleep-and-wake), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation)
- Verify: the executor's Rust tests (order checks, caller checks, journal recovery, pool order); Gateway feature tests for the signer, listener and fallback; `bin/swamp-shape-gate`; the proofs in the slice table
