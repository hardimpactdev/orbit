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

Renewal now brings temporary worker c online first, using its own enrollment, server token, state and cache. It waits for enrollment and a passed live probe, then checks c still has spare capacity, at least as many slots as the retiring worker, and the same connected instance. The original peer must still be accepting. Only then does the old base worker drain and restart. The peer and c preserve the pair's original eight accepting slots on 209 throughout the long drain.

We checked the simpler option of a fresh unit reusing an existing enrollment token. In this pinned release, `WorkerGateway` maps a single-machine token to one worker name and rejects a different instance while that name is connected (`already_connected`, `src/serve/worker_gateway.ts:778–787`). We ran the official test “a different instance cannot enroll while a worker is connected”; it passes. A fresh unit alone cannot overlap the old process with these existing credentials, so c needs a distinct enrollment identity.

Once the renewed base worker has a different instance UUID and passes its live probe, both base workers are checked before c drains and stops. This second drain also allows 75 minutes. One round renews only one base worker, bounding the two drains inside the existing three-hour maintenance deadline. Checks repeat five minutes after a round finishes; base connections become due at three hours. Readiness uses native Ansible retries (20 retries, two-second delay, three-second CLI calls); live probes remain capped at 125 seconds.

The oldest due accepting base goes first. Immediately before its drain, it must have more than 80 minutes of socket lifetime left, and its peer must have more than the existing three-hour maintenance budget left. Before c retires, each base must provide at least c's slot count, both before and after probing. All three instances must have more than 80 minutes left at that point. These are admission guards, not a guarantee against process failures or longer-than-budget dispatches. An unavailable base is restored first, without waiting for an older retained c to become fresh; recovery does not remove accepting capacity.

Failed replacement enrollment never drains a base worker. Failed base re-enrollment or verification leaves c accepting beside the peer. If both bases have recovered, the next retry only retires retained c and ends; another round may start five minutes later. Before retiring c, base workers with spare slots must pass their probes, and a fresh check requires both bases accepting with at least one spare slot in total. A full base is not probed. Peer failure during a blocking drain is detected after it returns and stops the sequence. A hung dispatch beyond 75 minutes can still be killed, and persistent failures can still prevent renewal before the socket cap. Worker-affinity groups remain unsuitable; Orbit does not use them.

Base worker names still come from each unit's latest enrollment receipt. Missing receipts log that renewal stopped and the eight-hour cap can still abort work. The new role-owned c name is the name minted by the renewal playbook, `swamp-role-<node>-c`; selection and verification use the same name. Official Swamp APIs check credential lifecycle metadata, and mint or rotate c's 30-day credentials only while its unit is stopped. Credentials with at most three hours left are rotated before another round. Each credential is saved before the next mint. Swamp's default `_token-secrets` vault recovers an interrupted file write without minting an existing token again; access mint returns a vault reference, not plaintext. Private vault reads and file writes use `no_log`, and files remain mode 0640. No credential file is inspected for its contents or checksum.

A read-only sample of 209 at 07:29:53Z showed four cores, load averages 0.36/0.64/0.48 and 5,518 MiB available of 7,887 MiB RAM. The a/b service groups used 598,302,720 and 386,203,648 bytes. Individual runner processes used about 192 MiB RSS; a main process plus four runners suggests roughly 1 GiB for a fully occupied c. That leaves useful RAM headroom for the temporary third worker. All twelve slots share four cores during overlap; this preserves dispatch admission, not additional physical CPU.

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

The committed Deno regression tests run the production Ansible playbook through real modules and disposable Deno CLI/systemd stubs. Explicit barriers assert that c is accepting before the old worker drains, two workers remain accepting during both long drains, and the renewed base worker accepts before c retires. Every fake drain refuses an accepting pool below the original eight slots. A queued follow-on dispatch completes on c while the original peer is full and a is still draining. Cases also cover a peer approaching socket expiry during a live probe, recovery beside a five-hour-old c, six-slot bases, capacity loss during verification, partial credential mint failure, expired credentials and rotation before c starts. The token stubs match the official array/list and vault-reference responses. Gateway tests pin the unit limits and timer.

The previous scratch proof against the pinned official DispatchService still shows that queued work can run on an accepting survivor while another worker drains. These tests do not claim a live systemd or fleet proof. No Node was changed by this implementation.

## Delivery and live proof

After this PR ships in a Gateway release and its Swamp load, apply `orbit node:role:add swamp swamp --converge`. The Ansible node phase installs the local Ansible renewal playbook and tasks and Gateway-rendered renewal units. The native **Apply Swamp worker unit** `@swamp/cfgmgmt/systemd` step in `workflow-operation-node-role-add.yaml` applies the worker template; it is not an Ansible worker-unit write. The existing dependent native job enables the timer after the worker-start job. Convergence leaves the active workers running. The timer then starts make-before-break renewal. Serve needs no restart for this mitigation. No live application or restart was performed in this PR.

Watch the renewal service and worker units a, b and c after convergence. Require c's enrollment and passed probe before any base drain, eight accepting slots during that drain, then the new base instance's passed probe before c drains and stops. Keep long agent/proof work and queued follow-on operations active across both drains; require no renewal-caused queue timeout or code 4002. Repeat a round for b and confirm c is stopped between successful rounds. No live application was performed in this PR.

## Upstream report status

This is a Swamp core bug: the hard socket cap aborts an in-flight dispatch despite normal credential refresh. **Not filed yet: no Lab login.** At the user's instruction, no login or credential was created tonight. The complete Lab draft is `~/migration-dashboard/drafts/swamp-lab-8h-session-cap.md`, with title, version, reproduction, evidence, source trace and expected versus actual behavior. The evaluation ledger records it as B8 with the same filing status. Nick will file it in the morning; no Lab number exists yet.
