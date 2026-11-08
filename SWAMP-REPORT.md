# Worker renewal can abort an active dispatch after eight hours

We run the official Swamp release `20261103.000636.0-sha.db3f6373`, with token-authenticated serve and two long-running workers on the same host. Thank you for the native worker drain support; it gives us a configuration workaround without patching Swamp. We may be missing a supported way to renew the authenticated connection safely.

## What we observed

On 2026-11-07, both workers remained in the same processes for about 24 hours. Worker `swamp-role-209-a` logged:

```text
14:40:30 Disconnected: control socket closed (code 4002: Session expired after 8 hours — reconnect to re-authenticate)
14:40:30 Dispatch caf4e867: command/shell.execute failed in 1745ms — The signal has been aborted
14:40:31 Enrolled as swamp-role-209-a
22:40:31 Disconnected: control socket closed (code 4002: Session expired after 8 hours — reconnect to re-authenticate)
22:40:31 Dispatch 6677b782: command/shell.execute failed in 791165ms — The signal has been aborted
22:40:32 Enrolled as swamp-role-209-a
```

Worker `b` logged the same expiry at 14:40:35 and 22:40:36. Serve accepted the same named server access tokens at 22:40:32 and 22:40:37. Orbit operation 13129 (`task_vm.image_refresh`) failed at its Ansible step with `Worker 'swamp-role-209-a' disconnected after step 'Run the Ansible phases' had written data — failing the run (write-then-drop)`. We read only the journal, process metadata and CLI help on the host. We did not read credential files or change anything there.

## Source trace

These are separate lifetimes:

- The enrollment token and server access token authorize enrollment and the WebSocket upgrade. Re-enrollment succeeds immediately after each drop; their expiry is not the eight-hour trigger observed here.
- The worker's [session credential](https://github.com/swamp-club/swamp/blob/db3f6373207cc41235d2334f19a7901b2b84620f/src/domain/remote/session_credential.ts) defaults to 15 minutes. [The worker](https://github.com/swamp-club/swamp/blob/db3f6373207cc41235d2334f19a7901b2b84620f/src/worker/connect.ts) calls `worker.session.refresh` at two-thirds of that TTL. [The gateway handler](https://github.com/swamp-club/swamp/blob/db3f6373207cc41235d2334f19a7901b2b84620f/src/serve/worker_gateway.ts) issues another credential.
- Independently, [`handleConnection`](https://github.com/swamp-club/swamp/blob/db3f6373207cc41235d2334f19a7901b2b84620f/src/serve/connection.ts) starts an authenticated socket's `MAX_SESSION_MS = 8 * 60 * 60 * 1000` timer. Worker session refresh does not reset it. It calls `closeSession` with code 4002; connection teardown detaches the worker and aborts active work. Reconnecting restores availability, but cannot restore a dispatch that already wrote.

We found no CLI setting for safe renewal of this eight-hour timer. The proposed newer official release `20261106.204821.0-sha.087900af` has [the same timer](https://github.com/swamp-club/swamp/blob/087900af78450db6d28be110d38542cce7c5307d/src/serve/connection.ts). Changing TMPDIR does not affect it.

## Could renewal preserve active work?

For reproduction, start an authenticated worker with the default persistent connection, keep it enrolled for just under eight hours, then start a dispatch that writes output and continues past that boundary. We expect a long-running worker's normal renewal to preserve that dispatch, or to stop admission and finish it before reconnecting. The observed behavior aborts it despite successful periodic worker credential refresh.

Could an authenticated worker revalidate its server credential on the existing connection, or use a native, time-based drain before this deadline? Revocation and real token expiry should still end access. Increasing token TTL or reconnecting after the forced close does not address the interrupted write. A systemd runtime restart alone moves the interruption to another time.

## Orbit's temporary mitigation

Persistent workers still use official Swamp, `--concurrency auto`, separate state/cache and the first SIGTERM's native drain. Each unit retains `KillMode=mixed`, `TimeoutStopSec=4500`, 30-second crash backoff and five starts per five minutes. A single systemd renewal service serializes maintenance outside worker dispatches.

The first mitigation drained one of the two workers after checking that its peer had a free slot. Under load that was insufficient. At 07:00:04Z on November 8 it began draining b; one long dispatch still held it at 07:29. The remaining a carried all new work, and vault.sync operation 13496 failed at 07:28 after its 30-second scheduling wait. A free slot at the start of a 10–30 minute drain did not preserve the pair's capacity.

The revised mitigation runs three permanent workers, a/b/c, each with distinct credentials placed before role convergence. The timer renews only the oldest due worker in each round. Its two peers remain connected and accepting throughout the single drain. There is no runtime token minting, secret retrieval, rotation, temporary slot or retirement drain.

We checked fresh units reusing an existing enrollment token. The pinned `WorkerGateway` rejects a different instance while the same single-machine identity is connected (`already_connected`, `src/serve/worker_gateway.ts:778–787`). Its official regression test passes. c therefore needs its own enrollment and full server access token, supplied by the same operator procedure as a and b. Our earlier runtime-mint proposal was incorrect: this release forbids remote reads from `_token-secrets`, and an access secret without its `<name>.` prefix cannot authenticate. We removed that mechanism and its recovery claims before merge.

One service serializes renewal outside worker dispatches. It requires all three connected workers with full `auto` capacity, unchanged instance UUIDs and more than 90 minutes of socket lifetime remaining. The two survivors must have at least one spare slot between them. Peers with spare slots must pass native live probes; full peers remain serving without a probe that would fail with `at_capacity`. After the one 75-minute drain, a new instance must enroll, restore capacity and pass its probe. Failure stops that round. A peer failure during the blocking restart is checked and logged after restart returns; no other drain follows.

Connections become due after three hours, with oldest-first checks five minutes after each round. Each round has a 90-minute deadline. Three full rounds with intervening five-minute waits fit by age 7h45 from a three-hour due cohort. This assumes healthy admission and reconnection, and early enough first activation. Two 4h45-old bases beside a fresh permanent c need only two drains; there is no c retirement that consumes the peer's remaining headroom. Persistent failures, full survivors or arbitrary hung work can still prevent renewal before the socket cap. Worker-affinity groups remain unsuitable; Orbit does not use them.

All three names come from their own units' latest enrollment receipts, including bootstrap and timestamped remint names. Missing receipts log that renewal stopped and the eight-hour cap can still abort work. The operator must place `/etc/orbit/swamp-role-worker/c/enrollment.token` and `server.token` as full `<name>.<secret>` tokens, root:orbit 0640 in a root:orbit 0750 directory on 209, before convergence. The role never creates credentials. No tokens were created or placed by this implementation.

A read-only sample of 209 at 07:29:53Z showed four cores, load averages 0.36/0.64/0.48 and 5,518 MiB available of 7,887 MiB RAM. The a/b service groups used 598,302,720 and 386,203,648 bytes. Individual runner processes used about 192 MiB RSS; a main process plus four runners suggests roughly 1 GiB for a fully occupied c. That leaves useful RAM headroom for the third permanent worker. All twelve slots share four cores; this preserves dispatch admission, not additional physical CPU.

We would prefer to remove this mitigation when Swamp supports renewal that preserves active dispatches.

## Signal proof and regression tests

A disposable process on mini invoked the unmodified pinned `workerConnectCommand` action, with its real SIGTERM handler and runner subprocesses. A loopback mock orchestrator/data server dispatched two actual built-in `command/shell.execute` steps. Each step used an explicit completion barrier. After an OS SIGTERM, both remained active and the control connection stayed open. A late dispatch was refused. Releasing the barriers produced:

```text
Draining (received shutdown signal) — finishing in-flight work...
Dispatch first: command/shell.execute succeeded
Dispatch second: command/shell.execute succeeded
Drain complete — disconnecting
Disconnected: drained
Worker stopped: received shutdown signal
exit 0
```

Observed protocol order: `SIGTERM`, first drain acknowledgment, new work refused, first success, second success, final drain acknowledgment, socket close. The test exercised the pinned CLI action directly, not the account-login wrapper or systemd. It used synthetic test enrollment, read no credentials and changed no Node. The local proof is at `~/tmp/orbit-swamp-signal-proof/proof.ts`; the official source checkout is `~/tmp/swamp-official-session-source` at `db3f6373207cc41235d2334f19a7901b2b84620f`.

The committed Deno regression tests run the production Ansible playbook through real modules and disposable CLI/systemd stubs. The read-only worker list and verify responses follow the pinned protocol. All credential and vault commands are rejected by the test boundary. Barriers hold a real playbook restart while checking two accepting peers, queued follow-on work, and re-enrollment before completion. Cases exercise a/b/c selection, successive 75-minute drains with an advanced fixture clock, two old bases beside fresh c, full peers, failed probes, changed capacity, session headroom, missing receipts and failed re-enrollment. These are orchestration tests, not an eight-hour live fleet proof.

The earlier scratch proof against the pinned official DispatchService shows that queued work can run on an accepting survivor while another worker drains. The signal proof above exercises pinned worker code; Gateway tests check unit limits and permanent worker intent. No Node was changed by this implementation.

## Delivery and live proof

The role reference lists the operator credential prerequisite for c. After valid tokens are placed, ship the Gateway release and its Swamp load, then apply `orbit node:role:add swamp swamp --converge`. The Ansible node phase installs the renewal playbook, tasks and rendered renewal units. The native **Apply Swamp worker unit** `@swamp/cfgmgmt/systemd` step applies the template. Its worker-start job enables and starts all three; the dependent native job starts the timer. Existing workers keep running, c starts, and serve needs no restart. No live application or restart was performed in this PR.

Watch the renewal service and all three worker units. Require two accepting workers/eight configured slots throughout each drain, and the renewed instance restored before another round. Keep long work and queued follow-on operations active; require no renewal-caused queue timeout, code 4002 or write-then-drop. All three workers must remain running between rounds. Watch unresolved-name or headroom failures: these stop renewal and leave the original cap risk active.

## Upstream report status

This is a Swamp core bug: the hard socket cap aborts an in-flight dispatch despite normal credential refresh. **Not filed yet: no Lab login.** At the user's instruction, no login or credential was created tonight. The complete Lab draft is `~/migration-dashboard/drafts/swamp-lab-8h-session-cap.md`, with title, version, reproduction, evidence, source trace and expected versus actual behavior. The evaluation ledger records it as B8 with the same filing status. Nick will file it in the morning; no Lab number exists yet.
