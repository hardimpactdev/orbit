# Serve execution capacity

At 20:11 UTC on 2026-10-28, process 281 used a full core and 5.2 GB RSS.
The only active method in the snapshot was runtime-retention/prune, started
20:05:20Z. The preceding completed pass took 531,013 ms (8m 51s). In the available
recent-history window, median coordinator times were groups-driver 7,715 ms,
maintenance-projects 1,804.5 ms, automatic-production-deploy 2,060.5 ms, and
maintenance-workers 693 ms. Each coordinator started twice in minute 20:04;
scheduled work replayed after the stalled retention pass. These are wall
times and counts, not a CPU profiler's allocation.

## Immediate change

Run retention in a separate native local process. Serve awaits child I/O,
which keeps its event loop available. The existing native executor receives
no new Node access or grant and uses the same datastore APIs and locks. Index
the input graph so referenced runs do not repeatedly scan every snapshot.
Keep the one-hour online grace, reference protection and bounded native deletes.
Give the child a two-minute deadline and terminate it on caller cancellation. After one second of grace, send SIGKILL
and reap a child that ignores SIGTERM.
Run retention hourly at minute 17 rather than every five minutes: its one-hour
version grace and seven-day history window do not require twelve full graph
scans an hour. Every pass retains the native 5,000-path deletion cap.
Do not lower the policy coordinator cadence to hide this defect: the measured
long-running work is retention. Cadence changes affect admission, deployment
and PR freshness and need evidence after this correction.

## Remote worker proposal — requires Nick's approval

Use Beast (Node 9) first, as a separate Orbit-owned systemd Process under a
dedicated unprivileged account. It already provides development compute and
CI capacity. Begin with two worker execution slots, then measure queue delay
and CPU before increasing. Keep Services as scheduler, dispatcher and state
owner. Do not copy the Services vault or grant Beast the standing driver token.

Enroll the worker with a short-lived Swamp enrollment credential and a narrow
worker identity. Permit outbound WireGuard HTTPS/WebSocket access to swamp.orbit
only. Its normal workflow placement must name the enrolled worker; missing
workers queue/fail within a deadline and never run the task in serve. Grant
only the Gateway operation families actually assigned to it. Do not grant
production SSH, sudo, instance credentials or arbitrary Gateway peer rights.
Repository analysis additionally needs its own approved one-repository token
contract: the current Gateway route deliberately permits Services Node 17 only.

Keep task-group maintenance on its existing task-bound workers, where the
checkout and package tools already live. Do not place them on a general worker.
The local datastore compactor also stays on Services in its separate process;
a Beast worker has no access to Services' filesystem, and exporting that
datastore/vault would cross the trust boundary. Stateless API-family execution
and CPU work with explicit inputs are the first general-worker candidates.

Nick chooses provisioning and access. This PR provisions nothing and changes
no live Process, schedule or access grant. A new dedicated worker Node is the
next option if Beast contention or isolation becomes a measured problem.
