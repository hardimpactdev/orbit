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

## Orbit's configuration workaround

The swamp role renders `--max-dispatches 1 --idle-timeout 5m`, retaining `--concurrency auto` and the persistent `--cache-dir`. The first completed dispatch starts Swamp's native drain. Remaining dispatches finish; two `worker.drain` acknowledgments ensure their replies reach serve before the worker closes. systemd uses `Restart=always` and `RestartSec=1` so a clean drain starts a fresh process and authenticated connection. No wrapper, timer, runtime kill, credential change or Swamp fork is involved.

The bound is five minutes idle, at most one three-hour run until the first completion, then at most another three-hour run admitted just before the drain: six hours and five minutes, below eight hours. A regression test ties that bound to Orbit's serve `--max-run-duration`. This assumes normal run cancellation and a responsive control connection; it does not promise recovery from a network loss or hung process. A native self-drain does not invoke systemd's five-minute stop timeout.

This workaround adds one second plus process startup between batches. It is suitable for Orbit's current workflows, which have no worker-affinity groups. Swamp deliberately fails an affinity group when its worker drains, so this is not a general replacement for safe connection renewal. We would prefer to remove the workaround when Swamp provides that path.

## Local validation

The five Gateway unit tests pass, including the unit flags, clean-exit restart policy and session-duration bound. The new lifecycle assertion fails on the old unit. Documentation lint and Gateway static analysis pass. A local proof against the unmodified pinned Swamp source held two dispatch slots with explicit completion barriers: the first success began draining, the second stayed connected and succeeded, both replies arrived, then the second drain acknowledgment preceded socket close. The pinned source's 47 worker connection and dispatch tests also pass, with its shared HTTP client initialized before test resource accounting. These checks do not replace the live renewal observation after activation.

## Delivery and proof

After this PR ships in a Gateway release, apply `orbit node:role:add swamp swamp --converge`. The existing Ansible role writes the Gateway-rendered worker template and reloads systemd; release Swamp load alone does not apply it. Convergence leaves active workers running. Activate the new command in the existing quiet restart window, after current operations finish; this PR performs no live restart. Serve does not need a restart for these flags, unless the separate official runtime upgrade is applied too.

Before activation, the next expected old-session expiry is about **2026-11-08 06:40:32Z** for `a` and 06:40:37Z for `b`. After activation, watch `orbit-swamp-worker@a`, `@b` and `orbit-swamp-serve`: `Draining (...) — finishing in-flight work...`, successful dispatch completion, `Drain complete — disconnecting`, then fresh enrollment. Keep a representative multi-step run active across a renewal and verify both its active dispatch and later steps succeed. At the old eight-hour boundary, there should be no code 4002 session expiry, aborted dispatch or write-then-drop failure. If the new command has not been activated, the old processes remain exposed.
