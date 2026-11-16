# Spike: signed Gateway orders over the observer's WebSocket

Task group 5552, subtask 5553, 16 November 2026. Built and measured in this task copy's nested fleet: `nested-gateway` (Node 1) and `nested-app-dev` (Node 3). Nothing merges and nothing touched production. Every spike unit, file, key and process has been removed from the fleet again, and the Node runs its original `orbit-agent` binary.

> **Round 2 is at the end of this page** ([Round 2](#round-2-no-systemd-on-the-orders-path)). It replaces the socket-activated executor with a long-running one that does the action itself. Everything from here to "How to run it again" is round 1.

## Summary

- The path works and every security test was refused and logged.
- Gateway to observer over Reverb is fast: **6 ms median**.
- End to end it is **not faster than a warm shared SSH connection** in this fleet. The unit starts at 94 ms median, against 61 ms for shared SSH and 195 ms for fresh SSH. The time goes to the per-connection systemd spawn (~45 ms), to `systemctl start` (~40 ms) and to the result's HTTP call into PHP-FPM (~35 ms). Each of these has a known faster form (see [Product needs](#what-product-form-would-need)).
- 9 of 260 orders (3.5 %) returned their result after the 300 ms bound. None was lost: the executor ran all 340 orders sent today exactly once. But the SSH fallback then woke the unit **a second time**. In product form the fallback has to be idempotent or carry the same Operation id.

## What the fleet already had

No stand-ins were needed:

- **Reverb.** The nested Gateway holds the `websocket` role (`orbit-websocket.service`).
- **Observer.** Both nested Nodes run `orbit-agent.service` as root, joined to `presence-node.{id}`.

The container has no C compiler. So for the build only, I installed rustup (minimal stable) and Zig 0.14 under `~/.cargo` and `/tmp/zig`, and used Zig as `cc`. Neither is in the repository.

## Design built

```
Gateway (PHP, nested-gateway)                 Node (nested-app-dev)
  sign(order) with Ed25519 secret key
  Reverb HTTP API ── event "spike-order" ──▶ orbit-agent (observer, patched)
     on presence-node.3                      │ passes the "order" string, unchanged,
                                             ▼ to /run/orbit-spike-exec.sock
                                           orbit-spike-exec.socket (root, 0600, Accept=yes)
                                             ▼ one short process per connection
                                           orbit-spike-executor
                                             SO_PEERCRED: uid 0, and /proc/<pid>/cgroup is
                                               exactly 0::/system.slice/orbit-agent.service
                                             verify_strict(Ed25519, pinned public key)
                                             then action ∈ {wake}, node == 3, target in local
                                               list, 0 < expiry − now ≤ 10 s, nonce new
                                             systemctl start orbit-spike-wake@<target>
                                             replies with JSON on the same socket
  POST /api/v1/agent/spike-result  ◀──────── observer posts the reply bytes with its
  (WireGuard peer + agent secret)            agent secret
  waits up to 300 ms, else SSH fallback
```

### 1. Gateway: sign and send

- **Key.** `sodium_crypto_sign_keypair()`. The secret key lived only on the nested Gateway, in `/etc/orbit-spike/gateway.key` (root:orbit 0640). The Node held only the public key.
- **Order.** A JSON payload: `action`, `node`, `target`, `operation`, `nonce` (16 random bytes in hex), and `expires_ms` (now + 3 s).
- **Envelope.** `base64(payload).base64(signature)`. The signature covers the exact payload bytes, so nothing is canonicalised. Signing took under 0.4 ms for every order.
- **Channel: the existing `presence-node.{id}`.** The agent already holds it, so there is no second subscription, auth round trip or reconnect path.
- **Why the channel is safe enough.** Only a holder of the Reverb app secret, meaning the Gateway, can publish a non-`client-` event. A client can publish only `client-*` events, and the observer routes only `spike-order`. A second private channel adds no protection that the signature does not already give, and the signature is the guard either way.

### 2. Observer: pass bytes on

The observer change is in [`observer.patch`](observer.patch) (+50 lines in `apps/agent/src/main.rs`; not applied in the tree).

- It adds one `Route::SpikeOrder` for event `spike-order` on the Node's own channel.
- It reads the event's `order` string and writes it unchanged to the socket.
- It reads at most 4 KiB of reply and POSTs that reply to the Gateway with the existing agent HTTP client.
- It holds no key and does not parse the order. It adds two timestamp headers, used only for the measurement.

### 3. Executor: check the caller, then the order

- **Code.** [`executor/src/main.rs`](executor/src/main.rs) is its own Cargo workspace, so it never touches `apps/agent/Cargo.lock`. It is pure Rust (`ed25519-dalek` 2, `verify_strict`).
- **Units.** [`systemd/`](systemd) holds them. The socket is root-owned, mode 0600, with `Accept=yes`, so nothing runs between orders. The idle state was a listening socket and no process ([`raw-idle-state.txt`](raw-idle-state.txt)).
- **Sandbox.** The service sets `NoNewPrivileges`, `ProtectHome`, `PrivateTmp`, `PrivateNetwork`, and writable `/var/lib/orbit-spike` only.
- **Check order.** Caller first, then the signature. Only signed bytes are parsed. After that come action, node, target, expiry (and at most 10 s ahead), then the nonce.
- **Nonces.** Each nonce is a file created with `O_EXCL`, so two connections with one order cannot both pass. Files whose expiry has passed are pruned.
- **Logging.** Every refusal goes to the journal (`orbit-spike-executor`) and back to the caller.
- **The action.** `orbit-spike-wake@.service` is a oneshot that writes `date +%s%N` to `/run/orbit-spike/started-<id>`. It stands in for `orbit-instance-wake@<id>`.

### 4. Result back

The result goes back as an HTTP POST to a spike route in the agent route group. It is guarded by `RequireActiveWireGuardPeer` and `RequireNodeAgentSecret`, like `agent/facts`. The route pushed the result into the nested Gateway's Valkey, and the waiting caller took it with `BLPOP`.

I chose HTTP over a `client-` event because the Gateway reads no client events in the request path. That would need the agent-view subscriber as an extra hop. The route was added to the shared checkout for the run and reverted afterwards: the nested Gateway serves this task copy's workspace.

### 5. SSH fallback

If no `done` result arrived within 300 ms of the broadcast, the bench ran the same wake over SSH. It used the Gateway's own key and the Node's user, over the shared connection.

## Latency

**Method.**

- 260 orders in three runs (60, 100 and 100), 200 ms apart, after 3 uncounted warm-up orders per run.
- Every time is measured from the moment before the Reverb publish call.
- All nested containers share one kernel clock, so Gateway and Node timestamps compare directly.
- SSH ran `systemctl start orbit-spike-wake@102 && cat …started-102` from the nested Gateway to the same Node: 90 runs over a shared ControlMaster connection and 90 fresh.
- Raw lines are in `raw-run*.jsonl`. [`summarize.py`](summarize.py) makes the tables.

All runs (1–3), in ms:

| Measure (ms from broadcast start) | Median | p90 | Max | Misses |
| --- | --- | --- | --- | --- |
| Order: Reverb HTTP publish returns | 5.6 | 10.2 | 27.6 | 9/260 |
| Order: executor process starts | 51.3 | 69.4 | 110.1 | 9/260 |
| Order: signature and checks pass | 52.3 | 72.1 | 112.4 | 9/260 |
| **Order: unit starts** | **93.5** | **122.8** | **180.1** | 9/260 |
| **Order: result reaches the Gateway** | **143.5** | **189.5** | **285.8** | 9/260 |
| Order missed: SSH fallback finished | 522.9 | 1369.2 | 4042.0 | 0/9 failed |
| **SSH, shared connection: unit starts** | **60.8** | **81.9** | **124.4** | 0/90 |
| SSH, shared connection: command returns | 76.7 | 109.0 | 151.2 | 0/90 |
| **SSH, fresh connection: unit starts** | **194.5** | **247.0** | **416.3** | 0/90 |
| SSH, fresh connection: command returns | 209.3 | 271.1 | 467.1 | 0/90 |

Where the time goes (run 3, 100 orders, with observer timestamps):

| Step (ms from broadcast start) | Median | p90 | Max |
| --- | --- | --- | --- |
| Reverb HTTP publish returns | 5.6 | 8.5 | 20.8 |
| Observer receives the event | 6.1 | 10.8 | 62.6 |
| Executor process starts (socket activation) | 51.6 | 72.1 | 108.3 |
| Signature and all checks pass | 52.5 | 74.0 | 110.1 |
| Unit starts (`systemctl start`) | 96.3 | 123.2 | 157.1 |
| Executor reply back at the observer | 110.1 | 146.0 | 193.9 |
| Result reaches the Gateway route | 144.5 | 188.8 | 268.2 |

Read across the rows, each step costs:

| Step | Cost |
| --- | --- |
| Reverb hop | ~0.5 ms |
| Per-connection unit spawn | ~45 ms |
| Ed25519 check and all order checks | ~1 ms |
| `systemctl start` (fork, D-Bus, oneshot) | ~44 ms |
| Observer → Gateway POST (TLS and a Laravel boot in PHP-FPM) | ~35 ms |

A variant run without the sandbox options ([`raw-run4-no-sandbox.jsonl`](raw-run4-no-sandbox.jsonl), 60 orders) started the executor at 39 ms median instead of 52 ms. So namespace setup costs about 12 ms, and the rest of the spawn is systemd's per-connection unit.

**About the misses.** A miss is a result later than 300 ms, not a lost order: the journal shows 340 `done` lines for the 340 valid orders sent today. The misses came in pairs and singles, and they line up with load on the 1–2 CPU nested containers. Fallback times are high (up to 4 s) for the same reason: the fallback ran while the late order and the SSH session competed for the Node's CPU.

**Limits of these numbers.** This is a nested fleet: the Gateway and Node containers have `limits.cpu=2`, and `nproc` reports 1 inside both, on a shared host. Reverb, PHP-FPM and the Node all run on one machine with no network distance. Absolute values will differ on real Nodes, but the split between the steps should hold.

## Security tests

All of them were run against the live path. The order tests went Gateway → Reverb → observer → executor. The caller tests connected straight to the socket on the Node with a valid, freshly signed order, so only the caller check could refuse. The evidence is in `raw-security-*.{jsonl,txt}`.

| Test | Refused | Where | Logged by the executor |
| --- | --- | --- | --- |
| Connection from another user (`nobody`), product socket 0600 | Yes | Kernel: `EACCES` on connect | No: the kernel refuses before the executor runs. See the next row. |
| Connection from another user (`nobody`), open test socket 0666 | Yes | Executor: `peer uid 65534 is not the observer's user` | Yes |
| Root outside the observer unit (interactive `incus exec` shell) | Yes | Executor: `runs in 0::/.lxc not orbit-agent.service` | Yes |
| Root in its own unit (`systemd-run --unit=orbit-spike-impostor`) | Yes | Executor: cgroup `…/orbit-spike-impostor.service` | Yes |
| Root in a look-alike unit (`orbit-agent-x.service`) | Yes | Executor: exact cgroup match | Yes |
| Bad signature (signed with another key) | Yes | `bad signature` | Yes |
| Changed action (`wake` → `destroy`, old signature) | Yes | `bad signature` | Yes |
| Changed target (`101` → `102`, old signature) | Yes | `bad signature` | Yes |
| Expired order (signed, expired 1 s ago) | Yes | `expired` | Yes |
| Replayed order (same envelope sent twice) | Yes, the second time | `replayed nonce`; the first send ran | Yes |
| Unknown action (signed `shell`) | Yes | `unknown action` | Yes |
| Extra: wrong target (signed, not on this Node) | Yes | `wrong target` | Yes |
| Extra: wrong Node (signed for Node 4) | Yes | `wrong node` | Yes |
| Extra: expiry too far ahead (signed, 60 s) | Yes | `expiry too far ahead` | Yes |
| Extra: garbage bytes | Yes | `malformed order` | Yes |

## What product form would need

### Observer (`orbit-agent`)

- One event name. Pass bytes, cap the size, read a bounded reply. Already in the patch.
- A bounded number of orders in flight. The spike spawns one task per order with no cap.
- The result endpoint bound to an Operation: record `done` or `refused` on it, instead of the spike's Valkey list. Or report through the existing facts endpoint if that fits the Operation model better.
- A **kept-alive HTTP connection** to the Gateway, plus a light result handler. The POST cost ~35 ms here, most of it a Laravel boot in PHP-FPM.
- No key and no parsing; these stay as built. Orders that arrive while the observer is reconnecting are lost. That is what the fallback is for.
- Ideally a dedicated system user instead of root. Then the socket's file mode alone keeps other root-less callers out, and the cgroup check stays as the second layer.

### Executor

- A crate in the `apps/agent` workspace, built and delivered with the agent, with socket and service units installed by the Node converge.
- `SO_PEERPIDFD` (Linux 6.5+) and reading the cgroup through the pidfd. This closes the PID-reuse window that `SO_PEERCRED` + `/proc/<pid>/cgroup` leaves.
- `StartUnit` over D-Bus (or `sd_bus`) instead of spawning `systemctl`. That removes most of the ~44 ms step.
- A choice between per-connection activation (`Accept=yes`: ~45 ms here, no process at rest) and `Accept=no` with a short idle exit (one process accepts many connections and exits after some seconds). The second keeps "nothing runs at rest" in the steady state and removes the spawn from the hot path.
- **Key distribution.** The pinned Gateway public key comes from the Node converge, with room for two keys during a rotation. The Node id comes from the agent's own configuration.
- **Target list.** It needs local truth: which Instances live on this Node. It should read local state that the observer cannot write, for example the Instance container's existence. The spike used a static file.
- **Nonce store.** Keep it in `/run` (expiry ≤ 10 s, so a reboot loses nothing that could still be valid), with a size bound.
- **Results.** Refusals reach the Gateway as results as well as the journal, so the Gateway can alert on them.

### Gateway

- Key custody: only the process that signs orders should read the secret key, not every Horizon worker.
- One signer, and one action allowlist shared with the executor's.
- The fallback has to be **idempotent with the order**. Either the action itself is idempotent (a wake of a running Instance does nothing), or the SSH fallback carries the same Operation id and nonce, so the Node refuses the second one.

## Open risks

1. **Double execution on a late result.** 3.5 % of orders crossed 300 ms here and then ran twice: once by the executor and once by the SSH fallback. Nothing in the spike ties the two paths together. Either the action must be idempotent, or both paths must share one dedupe key (the Operation id and nonce) on the Node. A larger bound trades this against the speed the feature is for.
2. **Root on the Node can impersonate the observer.** The caller check stops other users and other units. But root can stop `orbit-agent.service` and start its own binary under that name, or ptrace the observer. The real guarantee is the signature: even with root on the Node, an attacker can neither forge nor replay an order, but can drop orders. `SO_PEERCRED` with a cgroup read through `/proc` also has a PID-reuse race, which `SO_PEERPIDFD` closes.
3. **It is not yet faster than warm SSH, and expiry depends on clocks.**
   - **Speed.** The WebSocket leg is ~6 ms, but systemd activation, the `systemctl` spawn and the PHP result call make the unit start ~30 ms slower than a shared SSH connection. It is only ~100 ms faster than a fresh one. The gains listed above (D-Bus `StartUnit`, `Accept=no` with idle exit, a kept-alive result connection) are expected but not measured.
   - **Clocks.** The order's absolute expiry assumes the Gateway and Node clocks agree to well under the few-second TTL. This fleet shares one kernel clock, so skew was not tested.

## How to run it again

1. Build the executor in `executor/` and apply `observer.patch` to `apps/agent` and build it. Both are `cargo build --release`; the container needs a C compiler for `ring`.
2. Install the units from `systemd/` and the binaries on the Node.
3. Copy the files in `gateway/` to `/opt/orbit-spike` on the nested Gateway, and run `keygen.php` there as root.
4. Copy the public key to the Node's `/etc/orbit-spike/gateway.pub`. Write `node-id` and `targets` there too.
5. Append `spike-result-route.php` (minus its first three lines) to `apps/gateway/routes/api/agent.php`.
6. Run `php /opt/orbit-spike/bench.php 100 30` and `security.php` as `orbit` from `apps/gateway`.
7. Undo every step afterwards.

## Round 2: no systemd on the order's path

Nick's direction after round 1: the executor does the work itself and hands nothing to systemd. Built and measured on 16 November 2026, 14:55–15:05 UTC, in the same nested fleet. Afterwards every spike unit, target, file, key and SSH master was removed. The Node runs its original `orbit-agent` again, and the three changed files in `apps/` are reverted in the tree.

### Summary

- **Fast, and faster than SSH.** The target answers **19.6 ms** (median) after the Gateway's broadcast; p90 is 28.8 ms. With the result sent back on the observer's channel, it is at the Gateway at **25.4 ms** median, with 0 of 120 orders missing the 300 ms bound. The same thaw and check over SSH takes 27.6 ms on a shared connection and 132.5 ms on a fresh one.
- **Five targets at once.** All 20 orders (4 rounds of 5 targets) finished inside the bound. The last of five targets answered after 58 ms median.
- **Lock, idempotency, crash recovery and every security test work.** Details below.
- **The largest remaining cost is the journal's `fdatasync`.** It is about 4 ms per record, and an order writes three. A global state lock also makes concurrent orders queue behind each other's syncs.

### What was built

**Executor.** [`executor2/src/main.rs`](executor2/src/main.rs) is a tokio program with 4 worker threads. [`systemd/orbit-spike-executor.service`](systemd/orbit-spike-executor.service) starts it as a plain service (`Restart=always`, `RestartSec=100ms`), and systemd plays no other part.

- **Socket.** The executor binds `/run/orbit-spike-exec.sock` itself, as root with mode 0600.
- **Caller check.** `SO_PEERCRED` requires uid 0. `SO_PEERPIDFD` is available on this kernel (7.0) and was used for every connection (`pidfd=true` in the log and the reply). The executor checks that the pidfd names the same PID, reads `/proc/<pid>/cgroup`, and requires exactly `0::/system.slice/orbit-agent.service`. It then sends signal 0 to the pidfd: a live pidfd proves the PID was not reused between the cgroup read and the decision.
- **Order checks.** Signature (pinned key, `verify_strict`), action allowlist, Node, target list, and expiry (0 < expiry − now ≤ 10 s), as in round 1. Then the nonce check.
- **Seen nonces.** Kept in memory and appended to `/var/lib/orbit-spike/journal` with `fdatasync` before the executor acts. On start the executor reloads the nonces that have not yet expired and compacts the journal.
- **Per-target lock.** A `tokio::Mutex` per target. A second order for the same target waits. After the wait the executor checks whether the Operation is now done, so an order for the same Operation joins the first one's result. Different targets run in parallel.
- **Idempotency by Operation id.**
  - Before acting, the executor writes an `open` record with the Operation id and target.
  - After acting, it writes a `done` record with the result.
  - A later order for a done Operation, even one with a new and valid nonce, returns the first result marked `"repeat": true` and does not act.
  - A replayed envelope (same nonce) is refused as `replayed nonce`.
- **Crash recovery.** On start, every `open` record without a `done` is finished: the executor runs the action again (a thaw is idempotent) and records the result as `"recovered": true`. A later order for that Operation gets that result.

**The action, done in-process.** The nested Node has no Incus, so the targets are the brief's fallback:

- **Targets.** Each is a cgroup (`system.slice/orbit-spike-target-<id>.service`, set up by the test with `systemd-run`) running [`node/target.py`](node/target.py). The process holds 200 MB and answers HTTP 200 on port 8000 + id. There were five targets, 101–105.
- **Steps.** The executor writes `0` to the target's `cgroup.freeze`, then polls `cgroup.events` every 0.2 ms until `frozen 0`. It then sends HTTP `GET /` to `127.0.0.1:<port>` every 2 ms until it gets a 200, and reports ready.
- **Freezing.** The test script freezes the targets between orders over SSH ([`node/freeze.sh`](node/freeze.sh)), never through the executor. A frozen target does not answer HTTP (checked).
- **What the real wake would use.** The Incus local API: `PUT /1.0/instances/<name>/state` with `{"action":"unfreeze"}` over `/var/lib/incus/unix.socket`. Incus thaws through the same cgroup freezer, so the spike measures the kernel part, not the Incus API's own overhead.
- **An optimistic stand-in.** The thaw itself took under 0.1 ms here, and the HTTP answer followed 0.5 ms later, because the target was idle and in memory. A real app container waking from a long freeze or from swap will take longer.

**Observer.** [`observer2.patch`](observer2.patch) applies on top of round 1's patch. The Gateway picks how the result comes back with a `reply` field in the event, outside the signed order. The observer still never reads the order.

- `http`: the observer POSTs the result to the agent route with its agent secret. This is reqwest's pooled, kept-alive connection, as in round 1.
- `channel`: the observer sends the result as a `client-spike-result` client event on its own `presence-node.{id}` channel.
- **Who reads the channel result.** The Gateway's existing agent-view subscriber (`orbit-agent-view.service`), patched in [`gateway/agent-view-subscriber.patch`](gateway/agent-view-subscriber.patch). Reverb stamps every client event with the sender's signed member id, and the subscriber accepts the result only from `agent.{id}`.

### Latency: one order at a time

120 orders per reply mode, on one target, after 3 uncounted warm-up orders. Raw lines are in `r2-seq-*.jsonl`, and [`summarize2.py`](summarize2.py) makes the tables. All times are in ms from the moment before the Gateway's Reverb publish.

| Measure | Median | p90 | Max | Misses (300 ms) |
| --- | --- | --- | --- | --- |
| Observer receives the order | 4.6 | 9.2 | 57.7 | |
| Checks pass, nonce on disk | 14.8 | 23.0 | 64.2 | |
| **Action starts (thaw written)** | **19.0** | **28.3** | **68.9** | |
| **Action done (target answers HTTP)** | **19.6** | **28.8** | **69.5** | |
| **Result at Gateway: observer's channel** | **25.4** | **36.8** | **75.3** | **0/120** |
| **Result at Gateway: HTTP keep-alive** | **49.4** | **89.2** | **234.9** | **1/120** |
| HTTP run: action done | 20.0 | 28.3 | 161.4 | |
| HTTP run: missed order, SSH fallback target answers | 463.8 | – | 463.8 | 0/1 failed |
| SSH, shared connection: target answers (same thaw and check) | 27.6 | 44.3 | 119.6 | 0/30 failed |
| SSH, fresh connection: target answers | 132.5 | 168.0 | 198.4 | 0/30 failed |

Compared with round 1, where every path ended with `systemctl start`:

| Path | Round 1 median / p90 | Round 2 median / p90 |
| --- | --- | --- |
| Signed order: action done | 93.5 / 122.8 | 19.6 / 28.8 |
| Signed order: result at Gateway | 143.5 / 189.5 | 25.4 / 36.8 (channel) |
| SSH, shared connection: action done | 60.8 / 81.9 | 27.6 / 44.3 |
| SSH, fresh connection: action done | 194.5 / 247.0 | 132.5 / 168.0 |
| Misses at 300 ms | 9/260 | 0/120 (channel), 1/120 (HTTP) |

Where the ~20 ms go, from the step timestamps:

| Step | Cost |
| --- | --- |
| Gateway → observer over Reverb | ~4.5 ms |
| Observer → executor accept | ~0.2 ms |
| Accept → checks pass and the nonce record is synced | ~10 ms |
| Writing and syncing the `open` record before the thaw | ~4 ms |
| Thaw and HTTP check | ~0.6 ms |
| Writing and syncing the `done` record, then the reply | ~4 ms |
| Back over the channel | ~1.5 ms |

So about 12 ms of the 20 are the three `fdatasync` calls on this directory pool. The HTTP result path costs ~25 ms more than the channel, mostly the Laravel boot in PHP-FPM for each POST. It also has the long tail (max 235 ms) and the one miss.

### Concurrency: 5 targets at once

There were 4 rounds. Each round broadcast 5 orders back to back, one for each of targets 101–105, then waited for all 5 results. Raw lines are in `r2-burst-*.jsonl`.

| Measure (from the first broadcast of the round) | Median | p90 | Max | Misses (300 ms) |
| --- | --- | --- | --- | --- |
| Channel: action starts | 36.3 | 53.1 | 61.1 | |
| Channel: target answers | 39.8 | 53.7 | 62.3 | |
| Channel: result at Gateway | 65.9 | 85.3 | 93.3 | 0/20 |
| Channel: last of the 5 targets answers | 57.7 | 62.3 | 62.3 | |
| HTTP: target answers | 43.3 | 61.3 | 66.3 | |
| HTTP: result at Gateway | 155.7 | 184.5 | 195.1 | 0/20 |
| Lock wait inside the executor (different targets) | 0.0 | 0.0 | 0.0 | |

- **No waits on the target locks.** Different targets never waited on each other's lock.
- **But the five orders still queue.** Their action starts are spread over ~35 ms because each one takes the one global state mutex for its synced nonce and `open` records. A product executor should group several records into one sync, or use a lock per target for its records.
- **The HTTP result path is slow in bursts.** Five POSTs into PHP-FPM land at once, which puts its result at 156 ms against 66 ms on the channel.

### Per-target lock and idempotency tests

Raw lines are in `r2-same*.jsonl` and `r2-repeat.jsonl`.

| Test | Result |
| --- | --- |
| Two Operations on target 102, sent at once (action slowed by the 200 ms test pause) | The second waited **519.6 ms** on the target lock, then acted. Each got its own `done`. |
| One Operation sent twice at once (two nonces) on target 103 while 102 was busy | One acted. The other **joined**: `"repeat": true, "joined": true`, with the same `action_ns` and `ready_ns`. 103 ran in parallel with 102. |
| An Operation done, the target frozen again, then the same Operation in a new, validly signed order | The first result came back with `"repeat": true` within 16 ms. The target **stayed frozen** (`frozen 1`), so no second action ran. |
| Same order unchanged (same nonce) | Refused, `replayed nonce` |

Without the test pause, orders for one target arrived after the previous one had finished. Then they never overlapped: the second order of a pair just found the first one done.

### Crash test

Raw files are `r2-crash.jsonl` and `r2-crash-journal.txt`. A 3 s test pause sat between the thaw and the readiness check. The executor was killed with `SIGKILL` 1.3 s into the pause: the target was already thawed (`frozen 0`) but not reported ready.

1. **15:02:37.117.** SIGKILL. The observer saw the socket close and sent an empty result; the Gateway saw it 1.4 s after the broadcast. The Gateway learns that something failed, but not what.
2. **15:02:37.410.** systemd restarted the executor, 0.3 s later.
3. **15:02:37.517.** `executor ready: 1 open order(s) to finish`, then `recovering open order op=crash-…`.
4. **15:02:37.522.** `recovered … "done"`: the target answered and the result was journalled.
5. **15:02:40.** The Gateway resent the same Operation in a new order. It got the recovered result (`"recovered": true, "repeat": true`), and the executor did not act again.

The executor does not push a recovered result by itself, because it has no outbound path. The Gateway learns of it only by asking again with the same Operation id.

### Security tests against the round-2 executor

Raw files are in `r2-security-*`. Every refusal is in the executor's journal.

| Test | Refused | Where / reason | Logged |
| --- | --- | --- | --- |
| Another user (`nobody`), socket 0600 as the executor makes it | Yes | Kernel: `EACCES` | No: the kernel refuses first |
| Another user (`nobody`), socket opened to 0666 for the test | Yes | `uid is not the observer's user` | Yes |
| Root outside the observer unit (`incus exec` shell) | Yes | `pidfd=true: runs in 0::/.lxc` | Yes |
| Root in its own unit (`systemd-run`) | Yes | `pidfd=true: runs in …/orbit-spike-impostor2.service` | Yes |
| Root in a look-alike unit (`orbit-agent-x2.service`) | Yes | Exact cgroup match | Yes |
| Bad signature | Yes | `bad signature` | Yes |
| Changed action, old signature | Yes | `bad signature` | Yes |
| Changed target, old signature | Yes | `bad signature` | Yes |
| Expired order | Yes | `expired` | Yes |
| Replayed order | Yes, the second send | `replayed nonce` | Yes |
| **Replayed order across an executor restart** | Yes | `replayed nonce`: the persisted set was reloaded | Yes |
| Unknown action | Yes | `unknown action` | Yes |
| Extra: wrong target, wrong Node, expiry too far ahead, garbage | Yes, all four | As in round 1 | Yes |

### What product form would need (changes from round 1)

**Executor.**

- **The real action.** A crate in the `apps/agent` workspace, started by its own plain unit. It acts through the Incus local API (`PUT …/state` unfreeze, then the operation wait) and then the Instance's readiness check.
- **Fewer syncs.**
  - Batch the journal writes: one `fdatasync` for the nonce and the `open` record together, or a group commit across concurrent orders.
  - Take the state lock per target, not globally.
  - Keep a bound on journal size and compact it periodically, not only at start.
- **Recovered results reach the Gateway by themselves.** Either the executor keeps a queue of unsent results that the observer drains, or the observer asks after reconnecting. The Gateway should not have to retry to learn of them.
- **A rule for the done-Operation table.** It grows forever in the spike. Keep entries for the Operation's lifetime, or a fixed window.
- **Pidfd for a non-root observer.** The pidfd check stays as built. When the observer runs as a dedicated user, the socket's group and mode carry the first filter.

**Observer.**

- **Send the result on the channel.** It is ~25 ms faster than the HTTP POST, has a shorter tail, and adds no Gateway request.
- **One Gateway reader for the results.** The agent-view subscriber already reads the channel. It needs a real handler that records the result on the Operation and wakes the waiting caller. The spike used Valkey `BLPOP`.

**Gateway.**

- **Same-Operation retry is the fallback.** After the bound, resend the same Operation as a new order, and run SSH only when the WebSocket path is down.
- **Route SSH through the executor too.** The SSH fallback must also go through the executor's Operation journal, or act only on idempotent actions. In this spike the SSH fallback thawed the target directly, which is safe only because a thaw is idempotent. A product SSH path would hand the same signed order to the executor. That means one more allowed caller: a dedicated relay unit, not a shell.

### Open risks after round 2

1. **The SSH fallback does not share the executor's dedupe.** Over the WebSocket, idempotency holds: a repeat returns the first result, and a crash is recovered. The SSH path acts on its own. Any non-idempotent action, or a wake racing a later sleep, could run twice or out of order when a late WebSocket result and the SSH fallback cross. 1 of 240 orders crossed the bound here.
2. **The stand-in is optimistic, and the journal is on the hot path.**
   - The thaw-and-answer step took ~0.6 ms because the target was idle and resident. A real Instance wake through the Incus API, with an app that must answer after a long freeze, will add its own time, which this spike did not measure.
   - Three `fdatasync` calls (~12 ms) are now most of the latency. On a busy Node disk, they will drive the tail.
3. **Trust still rests on root and on clocks.**
   - Root on the Node can still replace `orbit-agent.service` and pass the caller check. Only the signature stops forged orders.
   - The absolute expiry still assumes the Gateway and Node clocks agree; this fleet shares one kernel clock, so skew was not tested.
   - A recovered order is reported only when the Gateway asks again.

### How to run round 2 again

1. Apply `observer.patch`, then `observer2.patch`, to `apps/agent`, and build it. Build `executor2/`.
2. Apply `gateway/agent-view-subscriber.patch`, and append the route as in round 1. Copy `gateway/*.php` to `/opt/orbit-spike` on the nested Gateway and run `keygen.php` there as root.
3. On the Node:
   - Install the executor binary, `systemd/orbit-spike-executor.service`, `node/target.py`, `node/freeze.sh` (as `/usr/local/libexec/orbit-spike-freeze`) and `caller-test.py`.
   - Write `/etc/orbit-spike/{gateway.pub,node-id,targets}`.
   - Start the targets with `systemd-run --unit=orbit-spike-target-<id> python3 …/orbit-spike-target.py <id>`.
4. Run `bench2.php` in the modes `seq`, `burst`, `same`, `repeat`, `crash` and `ssh`, and `security.php`, as `orbit` from `apps/gateway`.
5. Undo every step.
