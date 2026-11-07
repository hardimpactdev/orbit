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

Workers remain persistent, with `--concurrency auto` and their existing cache and machine identity. We removed per-dispatch recycling: a dispatch is one step, so `--max-dispatches 1` adds about 2.3–2.4 seconds per step and can make both workers drain while each still holds a long dispatch. The earlier six-hour-five-minute figure was an estimate, not a proven bound.

One systemd service controls both workers. It checks hourly and renews workers whose recorded enrollment is at least three hours old. Swamp preserves that timestamp across a same-process reconnect, so this age check is conservative. Before renewing a, it requires b's accepting status and an official `swamp worker verify b` check of the live pool. `systemctl restart` sends the first SIGTERM to a's main process; Swamp drains active dispatches. Ansible waits for a different enrolled instance and a successful native live check before it considers b. It never intentionally drains both. systemd serializes starts of the single renewal service; manual maintenance must use that service. Failed probes, unhealthy peers and failed re-enrollment stop the sequence. Five-minute service retries skip a freshly renewed connection. Enrollment readiness uses Ansible’s native retry (20 retries, two-second delay, each CLI call capped at three seconds). Each native live probe is capped at 125 seconds. The whole playbook is capped at 10,500 seconds, inside the service’s three-hour deadline.

Workers allow 75 minutes to stop (`TimeoutStopSec=4500`, `KillMode=mixed`), against an observed cold refresh step of about 50 minutes. Crashes use `Restart=on-failure`, a 30-second backoff and a limit of five starts in five minutes. A permanent startup failure hits this limit; maintenance retries after five minutes, rather than looping every second. The renewal service has a three-hour execution deadline; its failure stops the sequence without intentionally stopping the other worker.

With healthy peers and dispatches inside the stop allowance, an hourly age check plus two possible drains leaves headroom below the socket cap. This is operational headroom, not a proof for arbitrary workload duration or failures. A hung dispatch past 75 minutes can still be killed. An unavailable peer or a failed native probe can prevent renewal. A probe pass confirms the scheduling/data path. The exact native `at_capacity` refusal is also accepted with a fresh accepting-status check of the same peer instance after the probe. Verify selects connected workers, but checks capacity before drain status, so capacity alone does not prove admission. Every other probe failure stops renewal. This avoids waiting indefinitely for a probe slot under sustained load. These conditions must be visible in the service journal. Worker-affinity groups remain unsuitable for this mitigation because Swamp fails them on drain; Orbit's current operations do not use them.

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

The committed regression test runs the installed Ansible tasks through real built-in command and systemd modules, with fake CLI/systemctl boundaries. It holds a at drain and re-enrollment barriers. It asserts b remains accepting and is not restarted until a has a new instance and passes the native probe. It also covers an unhealthy peer, a failed live probe despite an idle record, a full but connected peer, a full peer that starts draining during the probe, an enrollment timeout and a retry that preserves a freshly renewed peer. This replaces the earlier arithmetic-only test. Gateway tests cover persistent worker flags, stop allowance, crash limits and the single timer/service rendering.

## Delivery and live proof

After this PR ships in a Gateway release and its Swamp load, apply `orbit node:role:add swamp swamp --converge`. The Ansible node phase installs the local Ansible renewal playbook and tasks and Gateway-rendered renewal units. The native **Apply Swamp worker unit** `@swamp/cfgmgmt/systemd` step in `workflow-operation-node-role-add.yaml` applies the worker template; it is not an Ansible worker-unit write. A new dependent native job enables the timer after the worker-start job. Convergence leaves the active workers running. One minute later, the timer starts sequential renewal. Serve needs no restart for this mitigation. No live application or restart was performed in this PR.

The old connections next expire about **2026-11-08 06:40:32Z** for a and 06:40:37Z for b. For the first cycle, allow two 75-minute drains plus startup and probes: begin activation by **03:30Z** to leave the full three-hour maintenance budget before expiry, rather than relying on the later 05:30Z application deadline. The observed 50-minute cold step is not a bound.

Watch `orbit-swamp-worker-renewal.service`, `orbit-swamp-worker@a`, `@b` and `orbit-swamp-serve`. Confirm active work succeeds during a's drain, a re-enrolls with a different instance UUID and passes its native live check, and only then b drains. Keep a representative multi-step run active across renewal and verify its later steps finish on available capacity. At 06:40Z there should be no code 4002 expiry, aborted dispatch or write-then-drop failure. Check that the timer remains active and the next cycles renew the workers well before eight hours.

## Upstream report status

This is a Swamp core bug: the hard socket cap aborts an in-flight dispatch despite normal credential refresh. **Not filed yet: no Lab login.** At the user's instruction, no login or credential was created tonight. The complete Lab draft is `~/migration-dashboard/drafts/swamp-lab-8h-session-cap.md`, with title, version, reproduction, evidence, source trace and expected versus actual behavior. The evaluation ledger records it as B8 with the same filing status. Nick will file it in the morning; no Lab number exists yet.
