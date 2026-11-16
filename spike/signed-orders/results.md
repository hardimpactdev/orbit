# Spike: signed Gateway orders over the observer's WebSocket

Task group 5552, subtask 5553, 16 November 2026. Built and measured in this task copy's nested fleet: `nested-gateway` (Node 1) and `nested-app-dev` (Node 3). Nothing merges and nothing touched production. Every spike unit, file, key and process has been removed from the fleet again, and the Node runs its original `orbit-agent` binary.

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
