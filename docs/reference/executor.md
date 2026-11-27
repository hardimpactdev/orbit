---
title: "Executor"
description: "How orbit-executor verifies callers and signed orders, maintains its journal, connects to the machine bus, handles signed container sleep and wake, and rolls out across Nodes."
covers:
  - apps/agent/{crates/executor-core/**,crates/executor/**,crates/instance/**,crates/process/**,crates/power/**,crates/firewall/**,crates/route/**,clippy.toml,contract/fixtures/{executor-order,executor-instance-destroy,executor-instance-sync-base,executor-instance-clear-base,executor-instance-work-read,executor-process-ensure-state,executor-schedule-ensure-timer}.json}
  - apps/gateway/app/{Domain/Nodes/NodeExecutorInput.php,Domain/Executor/**,Infrastructure/Executor/**,Console/Commands/ExecutorParityCommand.php}
  - tools/swamp-proof-guest/executor-order.php
  - swamp/ansible/tasks/node-executor.yml
---

# Executor

`orbit-executor` runs fast, typed Node actions from orders signed by the Gateway ([ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)). It lives in the `apps/agent` Cargo workspace with `orbit-executor-core` handling verification, journaling, and transport, and each action family in its own crate.

## Where it runs

Node convergence installs the executor on any Linux Node whose roles enable at least one action family: `app-dev` enables `firewall`, `instance`, `power`, `proof`, and `route`; `vpn` enables `dns`; `gateway` enables `proof`.

| Path | Content |
| --- | --- |
| `/usr/local/libexec/orbit-executor` | SHA-256 pinned binary from agent delivery (`executor-linux-x86_64`) |
| `/etc/orbit/executor/gateway.pub` | One or two base64 Ed25519 Gateway public keys (`ORBIT_EXECUTOR_PUBLIC_KEYS`) |
| `/etc/orbit/executor/node` | Numeric Node ID |
| `/etc/orbit/executor/enabled` | Enabled action families, one per line |
| `/etc/orbit/executor/nats.json` and `bus-guard.nft` | [Machine bus](#the-machine-bus) URL and root-only nftables guard (when NATS is enabled) |
| `/etc/orbit/executor/restore-threads` | Restore pool thread count (`32`; executor requires `16`–`256`) |
| `/etc/systemd/system/orbit-executor.service` | Runs `orbit-executor serve` with `Restart=always` |
| `/etc/sudoers.d/orbit-executor-relay` | Allows the Node user to run `orbit-executor relay` as `orbit-executor-relay` |

### One agent binary

`orbit-agent` on Linux also embeds the executor entry points (`orbit-agent execute serve` and `orbit-agent execute relay`). Both binaries share the same executor library crate, allowing future converges to run the executor directly from `orbit-agent` without a separate binary.

### Rollout

Convergence skips executor installation until the pinned agent delivery includes `executor-linux-x86_64` (`executor.release_missing`) and `ORBIT_EXECUTOR_PUBLIC_KEYS` is non-empty (`executor.keys_missing`). Families newer than the pinned agent version stay disabled automatically (`NodeExecutorInput::FamilySince`).

Configure the Gateway under `orbit.executor` in `config/orbit.php`:

| Setting | Environment | Default | Purpose |
| --- | --- | --- | --- |
| `public_keys` | `ORBIT_EXECUTOR_PUBLIC_KEYS` | empty | One or two comma-separated base64 Ed25519 public keys pinned on executor Nodes |
| `signing_key` | `ORBIT_EXECUTOR_SIGNING_KEY` | empty | Base64 64-byte libsodium secret key kept private on the Gateway |
| `wake_enabled` | `ORBIT_EXECUTOR_WAKE_ENABLED` | `false` | Route container wakes through signed executor orders |
| `power_enabled` | `ORBIT_EXECUTOR_POWER_ENABLED` | `false` | Route container sleeps, wakes, and lifecycle power orders through signed executor orders |
| `wait_ms` | `ORBIT_EXECUTOR_WAIT_MS` | `500` | Wait bound in milliseconds for CLI/API executor order replies |
| `visit_wake_wait_seconds` | `ORBIT_VISIT_WAKE_WAIT_SECONDS` | `30` | End-to-end budget in seconds for holding a visitor HTTP request while an Instance wakes |

To rotate keys, add the new public key beside the old one in `ORBIT_EXECUTOR_PUBLIC_KEYS`, converge all executor Nodes, switch `ORBIT_EXECUTOR_SIGNING_KEY`, remove the old public key from `ORBIT_EXECUTOR_PUBLIC_KEYS`, and converge all executor Nodes again so each Node installs the single-key `gateway.pub` and restarts `orbit-executor.service`.

### Build parity

Before enabling `ORBIT_EXECUTOR_WAKE_ENABLED` or `ORBIT_EXECUTOR_POWER_ENABLED`, verify every executor Node from the Gateway:

```bash
php artisan orbit:executor-parity [--json]
```

The command inspects each executor Node over pinned SSH and exits `0` only when every Node runs the pinned binary on disk and in memory, has an active `orbit-executor.service`, has no unrecorded frozen containers (`instance.frozen_unrecorded`), and passes the [activation proxy](#activation-proxy) check.

| Problem | Fix |
| --- | --- |
| `executor.build_differs` / `executor.missing` | Finish fleet rollout or run `orbit node:converge <node> --full` |
| `executor.running_build_differs` | Restart `orbit-executor.service` on the Node |
| `executor.inactive` | Check `journalctl -u orbit-executor` and converge the Node |
| `instance.frozen_unrecorded` | With signed power flags off, run `orbit instance:wake <id>` for each listed Instance |
| `caddy.config_unreadable` / `caddy.activation_proxy_wrong` | Check `caddy.service` and run `orbit node:converge <node>` |

#### Activation proxy

A visit to a sleeping Instance hits Caddy's `forward_auth` proxy, which rewrites the request to `GET /api/v1/runtime-activations/app-instance/<id>` on `https://gateway.orbit`. Both the executor's pre-sleep Caddy guard (`instance.sleep_route_unready`) and `orbit:executor-parity` read Caddy's admin API (`http://127.0.0.1:2019/config/`). They verify that every route carrying the asleep-marker matcher (`try_files /app-instance-<id>.asleep`) reaches a `reverse_proxy` with method `GET`, the exact activation URI, `Host`/`SNI` `gateway.orbit`, and an upstream resolving only to the active Gateway's WireGuard address.

## The socket and the caller check

`orbit-executor serve` binds `/run/orbit-executor.sock` (`root:orbit-executor-relay`, mode `0660`) atomically inside a `0700` directory. It authenticates every connection via `SO_PEERCRED`, `SO_PEERPIDFD`, `/proc`, and signal-0 liveness and accepts only:

- `root` inside cgroup `0::/system.slice/orbit-agent.service` (the observer).
- User `orbit-executor-relay` executing the exact executor binary (the relay).

All other callers are dropped without a reply. The socket serves up to 64 concurrent connections with a 10-second read bound and replies with two JSON lines: `{"status":"accepted"}` immediately, followed by the result.

## The order

Orders use the wire format `base64(payload).base64(signature)` (max 4 KB). Payload fields are `action`, `node`, `target`, `operation`, `nonce` (32 lowercase hex chars), `expires_ms`, optional `power_check`, and optional `input`. The executor validates in strict order:

1. Wire size and format (`order_too_large`, `order_malformed`).
2. Strict Ed25519 signature against pinned keys (`bad_signature`) and payload schema (`bad_payload`).
3. Allowlisted action (`unknown_action`) and enabled family (`action_not_enabled`).
4. Matching Node ID (`wrong_node`) and valid target (`unknown_target`).
5. Expiry not past (`expired`) and at most 10 seconds ahead (`expiry_too_far`).
6. Unseen nonce across restarts (`replayed_nonce`, carrying the first result if available).
7. Existing done journal record for the same Operation, action, and target (returns the cached result with `"repeat": true`).

Orders for the same target execute serially under a per-target lock; different targets run in parallel.

`discard` and `instance.destroy` accept a canonical positive Instance ID even after Incus has removed its container. Destroy verifies that the saved Incus project, container, pool, ACL, and optional inner volume belong to that ID before taking any action. This lets a new order recover a lost receipt or finish cleanup without weakening the live-target check for sleep, wake, or graceful stop.

## The machine bus

When `/etc/orbit/executor/nats.json` is present, `orbit-executor serve` also connects to the [Gateway engine](/reference/gateway-engine) NATS JetStream bus without credentials (admitted by WireGuard IP and guarded by `orbit_bus_guard` nftables so only `root` can connect).

On the bus, the executor reports `{actions, node_targets, features, version, clock_synchronized}` to `$KV.NODES.n<id>` every minute (`features` declares optional action inputs such as `instance.t3_pairing`) and pulls orders from durable consumer `node-<id>` on `ORDERS` (2 slots per CPU, clamped to 2–8). For each order, it verifies signatures and journal rules identically to the socket and fetches referenced blobs from `KV_CONTENT` into `/var/lib/orbit/executor/blobs` with SHA-256 checks. While running, it sends progress acknowledgements every 2 seconds, publishes the receipt on `orbit.receipts.<id>`, and acknowledges the order only after JetStream stores the receipt.

### Action primitives

The executor implements these action families:

| Primitive | Family | Behavior |
| --- | --- | --- |
| `proof.noop` | `proof` | No-op diagnostic targeting the Node |
| `sleep`, `wake`, `discard` | `power` | Signed container freeze, memory restore/unfreeze, and plan cleanup |
| `ufw.ensure_rule` | `firewall` | Idempotent UFW rule add/remove with post-change table verification |
| `tls.ensure_csr` | `route` | Creates RSA 2048 key once and returns CSR (`output`) for a domain |
| `route.publish_site` | `route` | Applies Caddy ACLs, `APP_URL`, links, PHP-FPM pools, leaf certs, root trust, and validated Caddyfile; `route.update` sends it twice, the side-by-side and then the lasting Caddyfile, never with `APP_URL` |
| `route.withdraw_site` | `route` | Withdraws a Route from the Node: guarded Caddyfile and pools without it, leaf folders removed, Caddy reloaded, admin API read back |
| `dns.publish_records` | `dns` | Atomically writes dnsmasq records and private DNS catalog, validates with `dnsmasq --test`, and restarts DNS units |
| `process.ensure_state` | `process` | Converges one Process to running or stopped and its boot policy, then reads it back (see [the process family](#the-process-family)) |
| `schedule.ensure_timer` | `process` | Enables and starts a Schedule's own Node timer, then reads back `UnitFileState`, `ActiveState` and the next elapse |

`route.update` sends `route.withdraw_site` over the lasting Caddyfile, for the old name and its staging leaves.

`route.withdraw_site` holds Swamp's publication lock, `/etc/caddy/.orbit-publish.lock`. It replaces the live Caddyfile only when it still holds the bytes whose digest the Gateway rendered from, or already holds the desired bytes.

It merges a shared pool by scope: a scope it changes must still hold its rendered-from or desired state, with the group as gid or name. It never creates an absent scope or pool. Any other content refuses `owned_revision_conflict` before a write. A failed pool test or reload, or Caddy reload, puts the previous files back. It reloads Caddy on every run, also on a repeat that changes nothing. It then reads `127.0.0.1:2019/config/` and fails `route.withdraw_unverified` while Caddy still names a withdrawn domain.

An executor with the `dns` family reports the `dns.guarded_records` feature. `dns.publish_records` holds the Node's DNS lock, `/run/lock/orbit-dnsmasq.lock`. With it, each `dns.publish_records` file may carry `from_sha256`, the digest of the bytes the Gateway rendered from. A file that holds neither those bytes nor the desired ones refuses `owned_revision_conflict`, and neither file is written.

### The instance family

The `instance` family (enabled on `app-dev`) executes clone lifecycle steps from Gateway-rendered inputs with no delivered scripts:

| Primitive | Work |
| --- | --- |
| `instance.clone` | Creates the network ACL, copies the default seed snapshot, attaches NIC filters and T3 proxy, and adds UFW route rules |
| `instance.fetch_branch` | Fetches the requested branch into `/var/lib/orbit/instance-repositories` using an HTTPS credential blob in a file of its own; with `seed` (feature `instance.fetch_seed`) it makes a missing mirror from the default's |
| `instance.prepare` | Removes inherited seed units on the stopped copy, assigns a fresh machine ID, and writes files (`files_blob`), JSON merges, symlinks, Orbit root CA / NSS trust, and agent folders |
| `instance.start` | Verifies NIC guards, unshifts ID maps, boots the copy, configures hostname and `/etc/hosts`, and runs `/usr/local/libexec/orbit-app-user` |
| `instance.checkout` | Checks out the branch inside the copy, merges `.env` from the seed and Gateway render, and excludes `.env` in `info/exclude` |
| `instance.t3` | Starts T3, waits up to 30 seconds for readiness, registers the project, and returns sessions (plus optional reserved-clone pairing) sealed to `seal_to` |
| `instance.destroy` | Force-stops the saved clone, removes its owned Incus storage, ACL and firewall rules, and discards both engines' freeze state before returning `done` |
| `instance.sync_base` | Imports what a task copy lacks of the pinned mirror head and moves only `refs/remotes/origin/<default>`, then returns the base evidence (`output`) |
| `instance.clear_base` | Removes a copy sync's Node scratch (`/var/tmp/orbit-task-base-<id>`), its pin in the mirror and the copy's bundle folder (`/tmp/orbit-task-base-<id>`); a repeat finds nothing and answers the same |
| `instance.work_read` | Read-only [signed read](#signed-reads) of the copy's workspace Git state beyond its baseline, as `agent` inside the container; answers the reader's JSON (`output`) |

`instance.sync_base` bundles the pinned head without the copy's `origin/<default>` and branch commits that the mirror also holds. Root makes the copy's bundle folder; `agent` fetches the bundle into the object store, so every file stays the agent's. Then `git update-ref <ref> <head> <old>` compares the ref with the value read before. Finally the primitive reads back the ref, HEAD and every local branch, and returns `behind_by` and the `work` read.

When `instance.t3` receives `pairing` (`{label, scopes}`) on an executor advertising `instance.t3_pairing` in `features`, it requests `POST /api/auth/pairing-token` using the Gateway session and seals T3's reply beside the sessions. A refused or unreachable pairing link never fails the step.

### The process family

The `process` family (`crates/process`) runs [`process.start`, `process.stop`](/reference/gateway-engine#processstart-and-processstop) and the Node-timer branch of [`schedule.enable`](/reference/gateway-engine#scheduleenable). App-dev enables it from agent 0.31.0 (`NodeExecutorInput::FamilySince`); app-prod does not enable it yet. Both actions target a part of the Node (`<node>:process-<id>`, `<node>:schedule-<uuid>`), so two orders for one Process or Schedule run one at a time. Every name is checked against the Process or Schedule it belongs to, and every command is a fixed argv. Each change runs only when the read state differs, so a repeat reports `applied` with 0 changes.

| Shape | Change | Read-back |
| --- | --- | --- |
| Host systemd unit | `systemctl enable` or `disable`, then `start` or `stop` | `LoadState=loaded`, `ActiveState` `active` or `inactive` exactly, and the admitted `UnitFileState` (`disabled` for a stop and for an on-demand start) |
| Host Docker container | `docker container start` or `stop`, by the exact saved name | `State.Status` `running` or `exited` exactly (`created` fails) and the admitted image; an inspect answer for another name fails |
| Schedule Node timer | `systemctl enable`, then `start`, of `orbit-schedule-<uuid>.timer` only | `LoadState=loaded`, `UnitFileState=enabled`, `ActiveState=active`; the receipt's `output` carries them with `NextElapseUSecRealtime` |

A unit in an Instance container is not covered: Swamp first moves the container's app account with a script, which no fixed argv reproduces, so the Gateway keeps that shape on Swamp. A property `systemctl show` leaves out never counts as its value. Each failure code names its phase: the change (`process.apply_failed`, `process.unit_missing`, `process.container_missing`), a stop's plain `systemctl stop` after it (`process.unit_stop_failed`), the read-back read (`process.read_failed`, `schedule.read_failed`) or a read of another state (`process.state_mismatch`, `schedule.state_mismatch`); the Gateway keeps these step codes beside the public `operation.native_failed` a Swamp run records.

### Signed reads

A read-only action (today `instance.work_read`) needs no Operation, stream or receipt. When the Node enables such an action, the executor also subscribes to `orbit.node.<id>.reads` on core NATS. The Gateway sends one signed order there as a request (`ExecutorOrderSigner::signRead`: `operation` `read:<target>`, expiry the request's remaining budget, at most 4 s) and waits for the reply. The executor checks the order exactly as it checks every order, fetches the content it names, runs it and answers its result line as the reply. It answers no other action there, so a change always takes the durable order stream.

A copy that does not run is `asleep` or `stopped` by the Node's asleep marker, and is never woken. A read writes no journal record, waits for no target lock and stores no result: a lost reply is read again with a fresh nonce, and the second read is fresh. The nonce map still refuses the same order twice, and an order that expired before it ran answers `executor.order_expired`.

The Gateway's whole read ends 4 s after it starts. The capability lookup (at most 1 s), the content publication and each send run on a connection of their own (`DeadlineRequest`). Its connect, the server's greeting, the handshake and the request all end by that deadline, and a reply after it is dropped. A request that never went out whole, that the server refuses (`-ERR`) or that nobody subscribes to is a failure to send. A connection that closes after the order went out, or a missing content acknowledgement, counts as a lost reply.

`instance.work_read` takes `{project, name, default?, budget_ms, state?}`. The name must be the target's own container, and `budget_ms` is the Node's budget (1 ms to 10 s, 2 s from the Gateway). `state` names content `{baseline, published}` by its hash, because a baseline may list 2,000 untracked files. Every Git call is the fixed reader of [workspace work state](/reference/development-containers#workspace-work-state), built in Rust as argv: `incus exec` … `runuser -u agent` … `git -c core.fsmonitor=false -c core.hooksPath=/dev/null --no-optional-locks`.

The calls, their environment and their overrides are exactly the SSH reader's, so both answer alike, submodules and filters included. Like the SSH reader, Git applies the workspace's own configuration and attributes, so configured `clean`, `smudge` and `process` filters run as `agent`, exactly as on the SSH path. Git writes no index, lock or ref. It runs no hook, writes no index and changes no ownership. The Node kills each command, with its whole process group, at the deadline to the millisecond. The read ends by `budget_ms` and by the order's expiry less 0.5 s, and the Node fetches its content within the order's life.

## The observer pass-through

`ExecutorOrderSigner` signs socket orders with a 5-second expiry (8 seconds for engine orders), and `ExecutorOrderPublisher` publishes them as `executor-order` on `presence-node.{id}` via Reverb.

`orbit-agent` treats `executor-order` as opaque bytes: it enforces the 4 KB size cap and 64 in-flight limit, forwards bytes to `/run/orbit-executor.sock`, reads replies for up to 10 seconds and 64 KB, and emits each line as `client-executor-result` on `presence-node.{id}` over `TCP_NODELAY`.

## Journal and recovery

The executor appends JSON records to `/var/lib/orbit/executor/journal`: `seen` on acceptance, `open` before execution, and `done` after completion (orders that expire while waiting for a target lock record `executor.order_expired` without acting). A background syncer fsyncs once per second and compacts live records (active nonces, open orders, and 24 hours of done records) every 10 minutes or 10,000 writes.

At startup, the executor creates the `journal.accepts` marker, marks any `seen`-without-`open` order as `executor.order_expired`, and replays any `open`-without-`done` order under its target lock before accepting new work on that target.

For the fenced actions (`instance.destroy`, `instance.fetch_branch`, `instance.sync_base`, `instance.clear_base`, and the Route update's `tls.ensure_csr`, `route.publish_site` and `dns.publish_records`), an order that expires before taking the target lock never settles the step, and a result goes back only after its `done` record is durable. A terminal receipt is sent only after the executor appends its `done` record. It retains a terminal refusal, such as `instance.removal_dataset_taken`, just like a successful removal. After a lost receipt, a fresh order for the same step returns that stored result without repeating the deletion, as long as the order expires within the [journal's replay window](/reference/gateway-engine#instancedestroy).

### Repeated receipts of a copy sync

The same holds for the `sync` and `clear` steps of [`tasks.sync`](/reference/gateway-engine#taskssync). Their `output` receipts are journaled. For example, a resend after a lost receipt repeats the stored base evidence without importing again.

A signed removal failure can include `detail`, a redacted diagnostic of at most 4 KiB. The executor keeps it with the result so a repeat or a restart does not discard the diagnostic. The Gateway redacts it again against the Instance's stored environment values before recording it for operators.

## The listener and the hand-off

The Gateway's [agent view subscriber](/reference/node-agent#gateway-view) receives `client-executor-result` on `presence-node.{id}`:

1. Verifies Reverb stamped `user_id` as `agent.{id}` and validates the payload shape and Operation ownership.
2. Records the first valid action result (and receipt timestamp) on the Operation in SQLite via a conditional `UPDATE`.
3. Logs deterministic refusals and records a failed Activity (`executor.order_check_failed`) for the Problem collector.
4. Pushes the verified wire line to Valkey list `executor:op:<operation>` (60-second TTL) for `ExecutorResultWait`.

`ExecutorResultWait` uses short `BLPOP` calls (requiring Valkey `hz 100` for tight timeout accuracy) and falls back to polling the SQLite receipt within the caller's budget.

## The wake inside the request

When `ORBIT_EXECUTOR_WAKE_ENABLED` or `ORBIT_EXECUTOR_POWER_ENABLED` is enabled (or the Instance was frozen by the executor), a visitor request to a sleeping Instance admits or joins `instance.wake` under the Instance lock and holds the HTTP request within `ORBIT_VISIT_WAKE_WAIT_SECONDS` (default 30 seconds).

- **On success:** The Gateway returns HTTP `307` with `Location` set to the visitor's original path and query (`X-Forwarded-Uri`, sanitized to a local path). The browser retries the exact method and body against the awake app.
- **On failure or timeout:** The Gateway returns a plain-text HTTP `503` naming the error code (or explaining that the Instance is still waking). There is no progress page or polling script.

### Wake handoff recovery

Visitor requests never enqueue follow-up jobs synchronously. Committed signed wakes and notices are picked up every 5 seconds by `ReconcileExecutorWakes` on `operations-prompt` (processing up to 50 completion handoffs and 50 lifecycle hook handoffs per pass, with 60-second retry leases and local kernel locks preventing concurrent hook execution). Durable Conn launches share the same admission lock so cancelling a launch never interrupts a wake joined by a visitor.

### Signed power delivery

With `ORBIT_EXECUTOR_POWER_ENABLED=true`, sleep and wake send one signed order over the Node's WebSocket with no SSH fallback. If no receipt arrives by the 6-second completion deadline, the Gateway performs a read-only SSH read of `/var/lib/orbit/executor/journal`:

- A `done` record settles the Operation identically to a live receipt.
- No record after order expiry fails the Operation with `executor.publish_failed` or `executor.not_accepted` and frees its lock.
- A `seen` or `open` record schedules follow-up journal reads (1 s, then every 10 s after 30 s).
- An unreachable Node or unprovable journal marks the Operation `interrupted` (`executor.result_unknown`), keeps the lock fenced, and retries every 10 s on the `operations` queue.

Fenced `instance.start`, `instance.deploy`, and Swamp recovery sleep/wake runs receive the same journal reads to release their lock once the run is confirmed over while keeping `interrupted` and `finished_at` ([Operations](/reference/operations)).

If a signed wake returns `instance.container_stopped`, the Operation hands off to the Swamp wake workflow to boot the container, using `sudo -n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay` on the Node for local signed orders.

### Switching engines

Every frozen Instance remembers which engine froze it (`signed_power` on the sleep Operation), and wakes always use the engine that froze it. Switching therefore requires no drain:

1. Run `php artisan orbit:executor-parity` with both flags off and wake any `instance.frozen_unrecorded` Instances.
2. Enable `ORBIT_EXECUTOR_WAKE_ENABLED` and `ORBIT_EXECUTOR_POWER_ENABLED`.
3. To roll back, turn both flags off. Instances frozen by the executor still wake through it, and new sleeps return to Python.

## The proof Operation

`executor.proof` runs a no-op `proof.noop` diagnostic directly from the Gateway without Swamp. Operators with target Node access and Gateway authority can trigger one via `POST /api/v1/nodes/{node}/executor/proof` (empty body and query), which waits up to `ORBIT_EXECUTOR_WAIT_MS` and returns the terminal Operation with HTTP 200.

## Proof key

Nested proof runs (`swamp/proof/node/converge.cases.yaml`) generate a throwaway Ed25519 key pair on the nested Gateway via `tools/swamp-proof-guest/executor-order.php` (`storage/app/private/executor-proof.key`, mode `0600`). The suite tests relay execution, order tampering, replay rejection, round-trip latency, and lost-result recovery.

## Rust sleep and discard

Signed `sleep` saves every non-`max` `memory.swap.max` limit in the container cgroup subtree and freezes via the Incus local API. It scans readable present and swapped pages (excluding kernel guard markers with bit 58) and atomically fsyncs the restore plan under `/var/lib/orbit/executor/power`. It then reclaims memory (waiting up to 2 seconds under the 32 MiB residual policy) and writes the asleep marker last.

`discard` removes the saved plan and limits record after a stop or fresh boot. Containers frozen by the kernel while Incus reports them running refuse with `instance.container_frozen_unmanaged`.

## Rust wake

Signed `wake` validates the container state (returning `instance.container_stopped` if stopped) and verifies plan bounds and live process identity via `pidfd_open` and start times. It faults non-resident pages back in parallel through the 32-thread restore pool (`process_madvise(MADV_WILLNEED)` when permitted plus `process_vm_readv`, capped at 2 seconds with a 250 ms grace). Finally, it unfreezes via Incus, restores exact cgroup swap limits, probes web port 8080 until an answer below 500 within `input.web.ready_seconds` (default 30 seconds), and removes the asleep marker.

| Result | Meaning |
| --- | --- |
| `done` | Container is running and answering, with all planned pages restored |
| `partial` (`instance.restore_deadline`, `instance.restore_stalled`, `instance.restore_read_failed`, `instance.restore_no_plan`, `instance.restore_plan_stale`, `instance.restore_plan_invalid`) | Container is running and answering, though memory pre-faulting was partial or skipped |
| `failed` (`instance.container_stopped`, `instance.container_start_failed`, `instance.container_frozen_unmanaged`, `instance.web_unready`, `instance.restore_readers_busy`) | Wake did not complete; asleep marker stays on `web_unready` |
