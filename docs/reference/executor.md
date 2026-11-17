---
title: "Executor"
description: "How orbit-executor checks callers and signed orders, keeps its journal, serves the relay command, how the Gateway hands its results to a waiting request, and how the role converge installs it."
covers:
  - apps/agent/{crates/executor-core/**,crates/executor/**,clippy.toml,contract/fixtures/executor-order.json}
  - apps/gateway/app/{Domain/Nodes/NodeExecutorInput.php,Domain/Executor/**,Infrastructure/Executor/**}
  - tools/swamp-proof-guest/executor-order.php
  - swamp/ansible/tasks/node-executor.yml
---

# Executor

`orbit-executor` runs fast Node actions on orders that the Gateway signs ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)). It is one Rust binary from the `apps/agent` Cargo workspace: the `orbit-executor-core` crate holds the shared parts, and each action family gets its own crate.

> **Slices 1 to 3 are built.** The executor, its install and its one action, the no-op `proof.noop`, exist. So do the Gateway signer and publisher, the observer's pass-through and the Gateway's [result listener](#the-listener-and-the-hand-off). An order goes out over the WebSocket, and its result comes back to its Operation. Slice 6 adds the Gateway and Caddy side of [the wake inside the request](#the-wake-inside-the-request), behind `ORBIT_EXECUTOR_WAKE_ENABLED=false`. The Rust wake action (slice 5) and the relay/Swamp fallback (slice 7) must land before it is enabled. The create's Node part arrives separately.

## Where it runs

The role converge installs the executor only on a Node whose roles enable an action family. Today that is `app-dev`, which enables the `proof` family. A Node with several roles enables the union of their families.

The converge writes:

| Path | Content |
| --- | --- |
| `/usr/local/libexec/orbit-executor` | The binary, pinned by its SHA-256 from the agent delivery (artifact `executor-linux-x86_64`) |
| `/etc/orbit/executor/gateway.pub` | One or two Gateway Ed25519 public keys, base64, one per line. The Gateway setting `ORBIT_EXECUTOR_PUBLIC_KEYS` names them |
| `/etc/orbit/executor/node` | The Node id |
| `/etc/orbit/executor/enabled` | The enabled families, one per line |
| `/etc/systemd/system/orbit-executor.service` | A plain service that runs `orbit-executor serve`, with `Restart=always` |
| `/etc/sudoers.d/orbit-executor-relay` | The Node user may run exactly `orbit-executor relay` as `orbit-executor-relay` |

The converge also makes the system user `orbit-executor-relay`. A changed binary or file restarts the service.

### Rollout

Nothing uses the executor yet, so a converge skips its install until the Gateway has both parts it needs. The intent then names the reason as `skip`, and the converge records `executor install skipped: <reason>` in its run:

- `executor.release_missing`: the Gateway's pinned agent delivery has no `executor-linux-x86_64` artifact.
- `executor.keys_missing`: `ORBIT_EXECUTOR_PUBLIC_KEYS` is empty.

Roll out in this order:

1. Publish a release whose agent delivery carries `orbit-executor-linux-x86_64`, and move the Gateway's agent pin to it. The release check accepts the executor beside the agents and checks its digest like theirs.
2. Set the Gateway's key pair: `ORBIT_EXECUTOR_SIGNING_KEY` and `ORBIT_EXECUTOR_PUBLIC_KEYS` (below).
3. Converge each `app-dev` Node.

The Gateway holds its executor settings in `config/orbit.php` under `orbit.executor`:

| Setting | Environment | Content |
| --- | --- | --- |
| `public_keys` | `ORBIT_EXECUTOR_PUBLIC_KEYS` | One or two base64 Ed25519 public keys, comma-separated. Each executor Node pins them |
| `signing_key` | `ORBIT_EXECUTOR_SIGNING_KEY` | The base64 64-byte libsodium secret key that signs every order. Its public half must be one of `public_keys` |

The signing key lives in the Gateway's own environment, readable by `orbit` and by no one else ([ADR 0266: Key custody](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#the-order)). Never print, log or commit it. Make a pair with libsodium's `sodium_crypto_sign_keypair()` as `orbit`, and write both halves, base64, straight into the Gateway's environment file, so neither reaches a terminal or a log. For a rotation, pin the new public key beside the old one, converge every executor Node, then switch the signing key and drop the old public key.

From then on the install is required, and a wrong pin fails the converge:

- A pin with no release URL fails with `executor.release_invalid`.
- More than two keys fail with `executor.keys_invalid`.
- A binary whose SHA-256 differs from the pin is never installed.

systemd only starts the executor at boot and restarts it after a crash. No systemd call, script, path unit or socket unit sits on an action's path.

## The socket and the caller check

The executor binds `/run/orbit-executor.sock` itself, with mode `0660`, owner root and group `orbit-executor-relay`. It binds the socket inside a new folder of mode `0700`, sets its mode and group there, and then renames it into place. So the socket is never reachable with wider rights. It accepts a connection only from:

- root whose cgroup is exactly `0::/system.slice/orbit-agent.service`: the observer, or
- the user `orbit-executor-relay` whose executable is exactly the executor binary: the relay.

It reads the peer with `SO_PEERCRED` and `SO_PEERPIDFD`, reads the peer's cgroup and executable in `/proc`, and then sends signal 0 to the pidfd. A live pidfd proves that the PID was not reused during the check. It refuses every other caller without a reply and logs the reason: `caller_other_user`, `caller_outside_observer_unit`, `caller_relay_other_program`, `caller_unknown`, `caller_unreadable` or `caller_gone`.

An allowed caller sends one order and gets two JSON lines: `{"status":"accepted"}` at once, then the result. The executor serves at most 64 connections at once. It waits at most 10 s for the whole order, however slowly the caller sends it.

## The order

The wire form is `base64(payload).base64(signature)`, at most 4 KB. The payload holds `action`, `node`, `target`, `operation`, `nonce` (32 lowercase hex digits) and `expires_ms`. An unknown field refuses. The executor checks, in this order, and refuses with the code in brackets:

1. The size and the form (`order_too_large`, `order_malformed`).
2. The signature, with `verify_strict` against each pinned key (`bad_signature`), then the payload's shape (`bad_payload`).
3. The action is on the allowlist (`unknown_action`) and its family is enabled on this Node (`action_not_enabled`). A valid signature does not enable a family.
4. The Node (`wrong_node`) and the target (`unknown_target`). The target of `proof.noop` is the Node itself.
5. The expiry: past (`expired`), or more than 10 s ahead of the Node's clock (`expiry_too_far`).
6. The nonce has never been seen, across restarts too (`replayed_nonce`). The refusal carries the first order's result once it exists.
7. The Operation: a done record with the same Operation, action and target returns the first result, marked `"repeat": true`, and does not act.

The log line of a `replayed_nonce` refusal ends in `first=<status>`, the status of the first result it carried, or `first=none` before that result exists.

A refusal line holds `"status":"refused"`, the `reason` and the Node's clock as `node_clock_ms`.

Orders for one target run one at a time under that target's lock; different targets run in parallel. The nonce and done maps are held only for one lookup or insert, with no I/O under them.

## The Gateway signer and the event

`App\Domain\Executor\ExecutorOrderSigner` builds and signs one order for an Operation. The order's `node` is the Operation's `node_id` and its `operation` the Operation's id as a numeric string; the signer refuses an Operation without an id or a `node_id`. The payload holds exactly the six fields above, in that order, as compact JSON. The nonce is 16 random bytes in lowercase hex, and `expires_ms` is the Gateway's clock plus 5 s. It also refuses an action outside the allowlist (`proof.noop` and `wake`), a target that is not 1 to 128 characters of `[A-Za-z0-9._:-]`, and a wire form over 4 KB. It reads its key with `ExecutorOrderSigner::fromConfig()` and refuses a missing or malformed one.

`App\Domain\Executor\ExecutorOrderPublisher` takes the Operation and its order. It publishes the order as the server event `executor-order` on `presence-node.{node_id}` of the Operation's Node, and refuses an Operation without a `node_id` or the order of another Operation. It publishes through the Gateway's one Reverb publishing path (`RealtimeConnection`), as the log stream and record events do. Only the Reverb app secret can publish a server event. The event's data is:

```json
{ "operation": "<the Operation id, a numeric string>", "order": "<base64(payload).base64(signature)>" }
```

The observer copies `operation` unread into every reply line, so a reply that carries no Operation (the `accepted` line, or a refusal before the payload is read) still finds its Operation. The [listener](#the-listener-and-the-hand-off) records a result only when that Operation's `node_id` is the Node that sent it. During a `websocket` move the event also goes to the old Node's Reverb, because an agent that connected before DNS moved still listens there. The agent holds one connection, so at most one copy reaches the executor. A failure on the serving Reverb throws, so the caller can take the fallback of slice 7 at once.

## The observer pass-through

The observer (`orbit-agent.service`) handles `executor-order` only on its own `presence-node.{id}`. It reads the event's `operation` and `order` and nothing inside either: it does not parse, verify or sign it. Then it:

1. drops an order over 4 KB, an event without an `operation` of 1 to 64 bytes and an `order`, and an order past 64 in flight, and logs why;
2. connects to `/run/orbit-executor.sock`, writes the order's bytes unchanged and closes its write side;
3. reads the reply for at most 10 s and 64 KB;
4. sends each complete line as the client event `client-executor-result` on the same channel.

The data of `client-executor-result` is exactly:

```json
{ "operation": "<the event's operation, copied unread>", "line": "<the reply line, unchanged, without the newline>" }
```

A line that does not fit one Pusher client frame (9,900 bytes) is dropped and logged; the session goes on. Lines sent before a bound passes stay sent. A missing socket sends nothing, and the Gateway's fallback covers it. Orders that arrive while the observer reconnects are lost; replies that finish then go out on the next session.

The observer's connection to Reverb sets `TCP_NODELAY`. A reply is two small frames, `accepted` and then the result. Without it, Nagle's algorithm held the result until Reverb's delayed ACK of the first frame arrived. That added about 40 ms to every round trip on the nested fleet: 46 ms median before the change, 4.0–6.2 ms after it.

Each order runs in its own task outside the observer's supervised tasks, because it holds no shared lock: a panic there loses that one order, and the observer goes on. The executor admits the observer by its cgroup, so the order needs no other proof of where it came from: the signature is the boundary.

## Journal and recovery

The journal is `/var/lib/orbit/executor/journal`, one JSON record per line. Before an action the executor writes an `open` record, and after it a `done` record. A repeat writes a `seen` record for its nonce.

One writer thread appends. Each connection thread hands it a record and waits until the record is written, so the `open` record is on file before the action runs. No record waits for a disk sync: a separate syncer thread syncs the file once a second. The page cache survives an executor crash, and a Node reboot expires every order.

The journal keeps only its live records: the nonces that have not expired, every order still open without a result, and the done records of the last 24 hours. The writer compacts the journal to these records every 10 minutes, or after 10,000 new records, while the executor runs. The syncer writes and syncs the compacted file. The writer then adds the records appended meanwhile and renames the file into place. Until that rename every record goes to the old journal, so a crash at any point leaves a complete journal.

At start-up the executor reads the journal, keeps the same live records and rewrites the journal with them. Then it runs every `open` order without a `done` record again and records its result. It pushes nothing by itself: the Gateway learns the result by sending the same Operation again.

## The relay command

`sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` reads one signed order from stdin, at most 4 KB, hands it to the socket, and copies the reply to stdout, at most 64 KB within 10 s. The Gateway's SSH fallback and Swamp steps use it.

## The listener and the hand-off

The observer sends each reply line back as the client event `client-executor-result` on `presence-node.{id}` ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), "The path", steps 4 and 5). Its data holds exactly `operation` and `line`, as [the pass-through](#the-observer-pass-through) sends them: the Operation id that the [publisher](#the-gateway-signer-and-the-event) put beside the order, and the reply line, unchanged, at most 64 KB. The `accepted` line and the refusals of the first five order checks do not name the Operation, so `operation` is how their reply finds it.

The [agent view subscriber](/reference/node-agent#gateway-view) is the listener. No other service and no second connection per Node takes part. For each event it:

1. It checks the sender. Reverb must stamp the event with the member `agent.{id}` of that Node's channel, as for `client-log`. It drops and logs an event from any other sender.
2. It checks the form. `operation` must be an Operation id, and `line` one JSON object with a known `status`. A line that names another Operation fails too. It drops and logs a malformed event.
3. It pushes an `accepted` line to the Valkey key `executor:op:<operation>:accepted`, and writes nothing to the database. Slice 7 reads this key for "accepted within 150 ms".
4. It records a result line on the Operation's `executor_result`, so the result is durable. A result line has the status `done`, `partial`, `failed` or `refused`.
5. It logs a `refused` result with its reason and the Node's clock. It also leaves a failed Activity with the code `executor.order_check_failed` on the Node. It logs a `failed` result with its code.
6. It then pushes the result line to `executor:op:<operation>`. A refusal is already logged and recorded by then, so a Valkey failure cannot hide it.

The record is one conditional `UPDATE`. It changes only an Operation of that Node that holds no result yet. A repeat changes no row, and neither does a second listener during a release ([ADR 0269](/decisions/0269-run-the-gateway-in-release-containers-with-frankenphp-worker-mode)). The listener still pushes that copy, because it may recover a lost push. It drops and logs a result for an Operation of another Node, or for no Operation.

Both keys live in the Gateway queue's Valkey, under its `orbit-gateway:` prefix. Each push is one Lua call that runs `RPUSH` and `EXPIRE` of 60 s together, so a key never exists without its expiry. If the database or Valkey fails, the subscriber logs it and goes on. The waiting request then falls back as for a lost result.

**The alert.** The [Problem collector](/reference/tasks#fingerprints) selects an Activity whose code ends in `_failed`. So each refusal counts toward the Problem `orbit:agent-view|executor.order_check_failed`, and the Activity's `error_message` names the reason. The subscriber saves the Activity without its model observers. The broadcast observer would send a Reverb HTTP request at once, and the socket loop must not wait for it. The properties sanitizer still runs. So `activity:list` and the collector see the row, but no browser gets a live Activity event for it.

**Cost in the socket loop.** We measured 300 results on a file SQLite database in WAL mode with 20,000 Operations and a local Valkey. The record write takes 0.12 ms median (0.15 ms p95), the push 0.05 ms median (0.07 ms p95), and the whole hand-off 0.19 ms median (0.27 ms p95, 2.1 ms at most). No queue job, HTTP call or Laravel boot sits between the listener and the waiting request. A refusal adds one Activity insert: in a second run of 300 results of each kind, a `done` result took 0.21 ms median (0.35 ms p95), and a refusal 0.54 ms median (0.84 ms p95, 8.8 ms at most). No broadcast fired.

**The waiting request.** `ExecutorResultWait::wait(operation, bound)` runs `BLPOP`, through `ExecutorResultQueue::pop`, on the result key and the `accepted` key until a result arrives or the bound passes. It returns `done`, `partial`, `failed`, `refused` or `timeout`, with the time to the `accepted` line, the elapsed time and the result line. The result key comes first, so a result that is already there wins. The bound is the Gateway setting `ORBIT_EXECUTOR_WAIT_MS`, 500 ms to start. The activation endpoint uses it when `ORBIT_EXECUTOR_WAKE_ENABLED=true`.

Valkey checks a blocked command's timeout on its server tick. With the default `hz 10`, a 200 ms bound ended after 297 ms. With `hz 100` it ended after 212 ms. A result that arrives is handed over at once, so only a timeout runs late. The Gateway's Valkey needs `hz 100` before slice 6 holds visits on this bound.

Two Pest files in `apps/gateway/tests/Feature/Infrastructure/Executor` test this. `ExecutorResultListenerTest.php` needs no server and runs in CI. It feeds the subscriber a fake Reverb stream and an in-memory queue, and covers:

- the sender and form checks, and the Operation of another Node
- the refusal's log, its Activity, and the Problem the collector records from it, with no broadcast
- a refusal that is still reported when the push fails
- the record before the push, and a repeat

`ExecutorResultWaitTest.php` tests the waiter. Its status and reason mapping, the `accepted` line and the bound run in CI, against an in-memory queue on a fake clock. Three of its tests start a real `valkey-server` with `hz 100` on a free port. They are in the `privileged` group, which the Gateway suite and CI exclude; run them with `vendor/bin/pest --group privileged` on a host with `valkey-server`. They measure what a fake cannot:

- the 60 s expiry
- a result 100 ms into the wait
- the timeout bound

## The wake inside the request

`ORBIT_EXECUTOR_WAKE_ENABLED` defaults to `false`. With it off, the activation endpoint and `instance:wake` keep the existing Python/SSH wake. With it on, container wakes use only a signed `wake` order through the observer. The target is the Instance id as a numeric string, on the Operation's Node, as ADR 0266 requires. The Gateway records or joins `instance.wake` under the Instance lock before signing. Requests and recovery share a publication receipt, so a joining visit publishes only if the order is missing. An already recorded result is usable even when another waiter consumed the Valkey copy.

The request shares one `ORBIT_EXECUTOR_WAIT_MS` budget, default 500 ms, across publishing and `ExecutorResultWait`. The publisher caps the Reverb HTTP call by the remaining budget. A `done` or `partial` result in time succeeds the Operation, records the runtime running and answers 200. Caddy's container route runs `forward_auth` before the application handlers in one ordered route: 200 continues the original request, including its method, URI, query and body. Caddy returns any other activation response unchanged. A slow wake, refusal, failure or transport error answers the existing 401 page with `X-Orbit-Runtime-Activation-State: pending`; its poll loads the app once the asleep marker goes.

Admission also queues `CompleteExecutorWake` on `operations-prompt` for 6 seconds after publication. It reads `executor_result` rather than holding a PHP request worker or another blocking pop. A late `done` or `partial` succeeds; `refused` fails with `executor.wake_refused`, `failed` with `executor.wake_failed`, and no result with `executor.wake_timeout`. The result stays on the Operation. A terminal Operation keeps its end.

Admission records `state.wake_executor_completion: pending` with the running Operation. After commit, requests and recovery take a cache lock for that Operation without waiting, sign and publish only if no publication receipt exists, then record `state.wake_executor_published_at` under the Operation lock. Publishing holds no database transaction.

The cache lock lasts 30 seconds if its worker dies; each publish is bounded by the remaining request budget, at most 500 ms for recovery. An uncertain publish can be retried with a fresh nonce for the same Operation; executor idempotency prevents another wake.

Only after publication does the handoff enqueue completion and record `queued` under the Operation lock. Publication and enqueue receipts commit separately: an enqueue failure cannot erase a successful publication. Either failure is logged and leaves the handoff pending. A later visit can retry within its request bound; the minute reconciler retries at most 50 pending handoffs, oldest first, once admission is six seconds old. Recovery works even if the setting has since been disabled. A publish failure stops that sweep and leaves it pending for the next pass.

A recovered order gets six seconds from its recorded publication for a result, rather than expiring against the old admission time. A result already recorded can complete immediately. Repeated sweeps and visits add neither another published order nor another completion job after their receipts commit. A wake that already ended needs no handoff.

Success hands the lifecycle follow-up, including Conn session renewal, to `operations-prompt` after commit. The job takes the Operation lock and records its receipt with the hooks' record changes, so another completion or a repeated job does not run successful hooks again. Operation notices also go to that queue after commit, carrying each change's notice and sequence. Neither lifecycle work nor Reverb delivery runs in the activation request or its termination callbacks. A slow vault write or broadcast therefore cannot keep that request worker busy past the wake bound.

The success transaction also records `state.wake_executor_follow_up: pending`. After commit, the hook handoff runs before terminal notice enqueues. It takes the Operation lock, enqueues the job, then records `queued`; an enqueue failure leaves `pending`. A later completion, including the already scheduled six-second job, retries that pending handoff even though the Operation succeeded. Repeating a queued handoff adds no job. The hook job records `delivered` with its existing successful-hook receipt, which still prevents repeat execution. Notice enqueue failures are logged and do not stop other callbacks or change the wake's result.

A failed hook enqueue after an in-bound success is also logged and remains pending for recovery; it does not change the activation response from 200.

The minute `ReconcileSwampOperations` pass also recovers pending hook handoffs, even after the completion job exhausts all three attempts. Each pass selects at most 50 succeeded executor wakes, oldest first, whose success is at least 30 seconds old. Older pending handoffs remain eligible, including when executor wakes have since been disabled.

The sweep uses the same Operation lock and queued marker as completion, so two sweeps or a late completion enqueue one job. It logs recovered Operation IDs as `Executor wake hook handoffs recovered.` A queue error stops this sweep, leaves pending work for the next pass and logs the Operation ID and exception class. Native reconciliation still proceeds.

The Gateway removes neither the container nor its marker on failure. A missing result fails to confirm the wake; the executor may still finish later. Its eventual result remains recorded without overwriting the terminal Operation. An explicit retry admits a new wake. This slice adds no SSH relay or Swamp fallback; slice 7 replaces this temporary failure policy with the ADR's fallback. Leave the setting off until slices 5 and 7 land. The Python path stays installed until slice 8.

The Valkey host prerequisite is `hz 100`, applied by the orchestrator, as [the hand-off](#the-listener-and-the-hand-off) explains. Even then the server tick can make a timeout run a little late; a late successful reply must not authorize the original request.

### Slice 6 proof case

`executor-wake-visits` in `swamp/proof/instance/wake.cases.yaml` specifies 50 visits to a real sleeping clone with T3 and Vite. It carries `waits: executor-wake-ready` and `fails_today` until slice 5's real wake is installed and the nested fleet and visit timing support can run it.

For each visit, sleep the clone, request its Route, and measure request start to the app's first byte, preserving the original request. Save all 50 timings and p50, p95 and max against today's 1.5 s warm / 2.4 s cold. Assert the app's own status/body, one wake Operation per sleep, a recorded `done` or `partial`, and readiness with the marker gone. Exercise an over-bound wake separately: the first response is the unchanged progress page and the poll eventually loads the app. Pest tests with a fake publisher prove the Gateway contract here; they provide no real-wake latency evidence.

## Checks

The executor crates set `[lints] workspace = true` and follow BeautifulRust ([ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor#rust-rules-p9)): its lints are the workspace's `[workspace.lints]`, and `clippy.toml` sits beside it. From `apps/agent`:

```sh
cargo clippy --locked -p orbit-executor-core -p orbit-executor --all-targets -- -D warnings
cargo test --locked -p orbit-executor-core -p orbit-executor
```

The unit tests cover every order check and refusal, the caller policy, the journal and its compaction, the replay across a restart and the recovery of an open order. `crates/executor/tests/socket.rs` runs the core's relay entry point against a real socket; the shipped binary takes only `relay`, with the fixed socket.

The Gateway and the executor share one fixture, `apps/agent/contract/fixtures/executor-order.json`. A throwaway key signed it and was discarded, so it holds only the public key and the signed order. `crates/executor-core/tests/gateway_order.rs` verifies it with `order::check`, and the Pest test `tests/Feature/Domain/Executor/ExecutorOrderSignerTest.php` checks that the signer builds the same payload bytes. Pest tests also cover expiry, nonce uniqueness, the size cap and the publisher (`tests/Feature/Domain/Executor`). The observer's tests in `apps/agent/src/realtime.rs` run the pass-through against a fake socket: bytes unchanged, the 4 KB cap, 64 in flight, the 10 s and 64 KB reply bounds and a missing socket.

## The proof Operation

The Operation kind `executor.proof` exists only for the proof. Each one is a real Operation row with `node_id` set to its Node ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) D7). Its target is the Node, as for `proof.noop`, and its input is `{"action": "proof.noop"}`. Its orders go out as `executor-order` on `presence-node.{id}`, and the listener records its result on it.

The kind's chart is [`docs/cli/proof/executor-proof.mmd`](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/proof/executor-proof.mmd). No page places it, because no command starts the kind. `ExecutorProofOperation` (`apps/gateway/app/Domain/Executor`) holds the whole kind:

1. `run` holds one proof run. The run records each Operation as `running` through the `start` closure it gets.
2. `send` signs a new order, with a new nonce, and publishes it. It refuses a closed Operation and every other kind.
3. `wait` waits for the result through `ExecutorResultWait` within `ORBIT_EXECUTOR_WAIT_MS`, as a waiting request does.
4. `finish` closes the Operation: `succeeded` on a `done` result, which it also stores as the result, and `failed` with `executor.proof_<status>` otherwise.

When a run throws, `run` fails every Operation the run recorded that is still open, with `executor.proof_aborted`. So no proof Operation stays `running` after a failed publish or wait.

The Gateway runs and closes the kind itself. It starts no Swamp run, and `proof.noop` changes nothing on the Node.

`tests/Feature/Domain/Executor/ExecutorProofOperationTest.php` covers the kind in CI, with a faked publish and an in-memory Valkey. It checks the row and its `node_id`, a new nonce for each order, a result the listener records reaching the waiter, a lost hand-off recovered by a repeat, a failed result, and that the kind cannot be reached from outside. Two more tests make a run throw, once with Valkey down in a wait and once when Reverb refuses the third publish of a burst. Both check that no Operation is left `running`.

**Who can create it.** Only the proof support on a nested Gateway, `tools/swamp-proof-guest/executor-order.php`, run there by the proof runner as the Gateway's managed user. That script is the only caller of `ExecutorProofOperation`. No route, CLI command, job or schedule names the kind. It has no direct Operation declaration, so the Node routes under `direct-operations/` refuse it with `operation.direct_invalid`. `ExecutorProofOperationTest` holds this: no route that starts an Operation names the kind, and only `OperationKinds` and `ExecutorProofOperation` name it in the Gateway's code. To create one you must run PHP as the Gateway's user, which can already sign any order ([Key custody](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#the-order)).

**Recovering a lost result.** A result can reach Valkey and still be lost: the request that took it ended, or the key expired. The Gateway then sends a new order for the same Operation, with a new nonce. The executor finds the done record of that Operation, action and target, and answers `repeat` with the first result without acting. The listener changes no row, because the Operation already holds a result, but it pushes the copy again. The waiting request then gets it.

## Proof key

The Swamp proof of `node:converge` (`swamp/proof/node/converge.cases.yaml`) sends a signed `proof.noop` through the relay on the nested `app-dev` Node. Its runner step `executor_key` runs `tools/swamp-proof-guest/executor-order.php` on the nested Gateway as its managed user. It makes one Ed25519 key that only the proof uses: the secret half stays in that Gateway's own `storage/app/private/executor-proof.key`, mode `0600`, and becomes that Gateway's `ORBIT_EXECUTOR_SIGNING_KEY`; the public half goes into that Gateway's own `.env` as `ORBIT_EXECUTOR_PUBLIC_KEYS`. The step then clears the Gateway's config and restarts Horizon.

`executor_order` signs each order with the same script and hands it to the relay; a relay order names no Operation of the Gateway. `executor_publish`, `executor_round_trip` and `executor_lost_result` send orders over the WebSocket through the Gateway's own signer and publisher, so they reach the executor only through the observer. Each of their orders belongs to its own [`executor.proof` Operation](#the-proof-operation) on the Node, so the listener records its result like any other.

### Orders over the WebSocket

The step `executor_publish` has two options for the order security tests:

- `tamper` makes the order of each order security test: `bad_signature`, `changed_action`, `changed_target`, `expired`, `unknown_action` and `wrong_node`.
- `replay` publishes each order twice, the exact same wire for the same Operation, before the step's one wait.

The copy therefore arrives inside the first one's 5 s expiry. The case expects the copy refused with `replayed_nonce` and the action run exactly once for that Operation. The two copies race through the observer, so the refusal may carry the first result (`first=done`) or not yet (`first=none`); both are correct. The executor's unit tests prove that a replay after the original completed carries the stored first result.

The cases `executor-order-security` and `executor-websocket-burst` (50 orders, with the latency from the Gateway's publish to the executor's result saved as evidence) use it. No key is committed, and the secret half never leaves the nested Gateway.

### Round trips and a lost result

Two cases prove the slice 3 row of ADR 0266: "50 round trips; result in a waiting request; a lost result recovered by a repeat".

**`executor-round-trip`** runs `executor_round_trip` with `count: 50`. The proof support runs 50 `executor.proof` Operations, one after another. Each one publishes its order and waits with `ExecutorResultWait`. Each trip needs three things:

- a `done` result in the waiter;
- the same line recorded on its Operation by the listener;
- one `done` record for its Operation in the executor's journal.

The evidence `executor-round-trip-<operation>.json` holds every trip. It gives the latency from just before the publish to the result in the waiter as p50, p95 and max, the time to the `accepted` line, and the wait's bound.

**`executor-lost-result`** runs `executor_lost_result`. The proof support runs one `executor.proof` order. A first wait takes its result and drops it. The support then deletes the Operation's Valkey keys, and a second wait must time out. Next it sends a new order, with a new nonce, for the same Operation. The step needs:

- `repeat: true` in the waiter;
- the same result as the first, apart from `repeat` and the Node's clock;
- the first result kept on the Operation, and the Operation `succeeded`;
- one `done` record for the Operation in the journal.

**Measured.** On 2026-11-17, task 5739 ran both steps by hand on its copy's nested fleet, without the converge. The executor and an observer with the pass-through were built from the branch and installed on the nested `app-dev` Node. The proof key was pinned through `executor_key`'s script. Each run made 50 round trips. Latency runs from the publish to the result in the waiting request:

| Run | p50 | p95 | max | `accepted` p50 | `accepted` p95 | `accepted` max |
| --- | --- | --- | --- | --- | --- | --- |
| Observer without `TCP_NODELAY` | 46.0 ms | 54.1 ms | 88.3 ms | 0.9 ms | 10.6 ms | 36.0 ms |
| With `TCP_NODELAY`, run 1 | 6.2 ms | 27.7 ms | 35.3 ms | 1.6 ms | 22.8 ms | 30.9 ms |
| With `TCP_NODELAY`, run 2 | 6.2 ms | 11.5 ms | 13.4 ms | 1.3 ms | 6.1 ms | 6.6 ms |
| With `TCP_NODELAY`, run 3 | 6.2 ms | 13.8 ms | 14.5 ms | 1.3 ms | 7.3 ms | 9.6 ms |
| With `TCP_NODELAY`, run 4 | 4.0 ms | 7.0 ms | 38.1 ms | 0.7 ms | 1.6 ms | 33.7 ms |
| With `TCP_NODELAY`, run 5 | 4.7 ms | 13.9 ms | 32.0 ms | 1.2 ms | 5.2 ms | 27.8 ms |

The executor wrote its `done` record 3–4 ms after the publish (median of 50 orders, `executor-websocket-burst`'s step). Every trip was `done`, recorded on its Operation and run once.

**The tail is not explained yet.** The `accepted` line shows the same tail: its p95 was 1.6–22.8 ms and its max up to 33.7 ms with `TCP_NODELAY`. That line leaves the executor at once, so the tail comes before the executor answers, on the publish or the Reverb side.

**The lost result.** In each of the six lost-result runs the second waiter timed out. Its bound was 500 ms (`ORBIT_EXECUTOR_WAIT_MS`), and it returned after 504–575 ms. This lateness is the one [the waiting request](#the-listener-and-the-hand-off) describes: Valkey checks a blocked `BLPOP`'s timeout on its server tick, and the nested Gateway's Valkey runs the default `hz 10`. The activation path requires the orchestrator to set `hz 100`. The repeat then brought the first result back in 7–19 ms. The journal held `open`, `done` and `seen` for that Operation: one run.

**Status.** In the proof plan both cases keep `waits: idmap`, like every `app-dev` converge case: the converge builds the Instance host's stock image, which needs the 2,000,000 ID map. Both also keep `fails_today`, because the pinned agent delivery has no `executor-linux-x86_64` and no observer release with the pass-through yet.

