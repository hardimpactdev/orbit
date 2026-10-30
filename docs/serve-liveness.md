# Serve liveness and inode capacity

Node 209 runs the independent `orbit-swamp-liveness.timer` once a minute. It reads serve's public loopback health and the Gateway's peer-authorized claimable summary. It needs no token, vault, datastore or worker cache access. Root-owned systemd controls serve392 directly; the Process API cannot recover the orchestrator it depends on.

The probe restarts serve after health has failed for three minutes, or claimable work has remained unchanged for three minutes while its oldest item is more than three minutes old. A draining backlog resets the progress timer. An unavailable Gateway read does not establish a stall. Startup has a three-minute grace period; restarts have a ten-minute cooldown, persisted before the restart command. The probe never changes schedules or Operations. Planned unit-control loads stop this timer and its current probe, then restore its previous timer state after readiness; dispatch schedules remain active.

The datastore has its own 64 GiB ext4 volume with 16,777,216 inodes. The probe checks both `/` and `/home/orbit/apps/orbit-swamp/default/.swamp` through an empty root-owned bind mount at `/run/swamp-datastore-inode-probe`, and reports the filesystem with the least free inode capacity. `ProtectHome=yes` stays enabled. The probe emits a journal warning at 80% inode use (20% free) and above and a critical alert below 2%. Below 1%, it reports inode exhaustion and suppresses automatic restart: restarting cannot restore storage capacity. Check both `df -h /` and `df -i / /home/orbit/apps/orbit-swamp/default/.swamp` when a write returns `ENOSPC`.

Install the checked-in files through the existing independent host-control route:

```sh
sudo install -d -o root -g root -m 0700 /home/orbit/apps/orbit-swamp/default/.swamp/.inode-probe
sudo install -D -m 0755 bin/swamp-liveness.py /usr/local/libexec/orbit-swamp-liveness.py
sudo install -m 0644 systemd/orbit-swamp-liveness.service /etc/systemd/system/
sudo install -m 0644 systemd/orbit-swamp-liveness.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now orbit-swamp-liveness.timer
sudo systemctl start orbit-swamp-liveness.service
journalctl -u orbit-swamp-liveness.service -n 5 --no-pager
```

The service uses root-owned private state under `/var/lib/orbit-swamp-liveness`, a read-only filesystem sandbox, no home access and `NoNewPrivileges`. Its only mutation outside that state directory is the fixed serve unit restart through systemd. Roll back by disabling the timer and removing these two unit files and the installed script. Preserve its state and journal for diagnosis. This does not grant worker or package processes any new privilege.

Native metadata retention is enabled in `.swamp.yaml`: runs keep seven days; finished evaluated snapshots and outputs keep one hour. Native bookkeeping keeps a one-hour grace for ended leases/dispatches and stale disconnected workers; active leases, waiting dispatches and worker-referenced tokens survive. The `data` key remains absent. This prevents metadata cleanup from deleting exact data versions referenced by durable receipts. The native fire-record reaper already keeps four hours and runs every ten minutes. Keep the old online planner paused. See [compaction](services-compaction.md) for protected data cleanup and backup rules.

For emergency metadata cleanup, first verify a fresh consistent `.swamp` archive and the protected receipt hashes. Before any planned stop, append the reason and expected duration to Beast’s `~/swamp-execution/THREADS.md`. Keep each stop below two minutes. Use independent unit control for a bounded offline native GC pass, restore serve before that deadline even if GC is incomplete, then run `swamp run gc --dry-run --json` with the configured policy. Review its counts, run `swamp run gc --force --json`, and verify those hashes again. Use the existing account files without printing them. No `data gc` is part of this procedure. Load the approved source, start serve and verify schedule pause states plus a real claim. Record before/after inode counts, the backup checksum, GC totals and claim time in `FLEET-CHANGES.md`.

Operation families use 30-second schedules; database, dependency merge, integrations and firewall use 60 seconds. A tick admits at most eight parallel claim slots and four of one kind, visiting oldest kinds first. Eight fixed, short slot model names per family avoid Swamp's model lock and name-length failures. These bounds apply to discovery hints; the Gateway still atomically selects each Operation. Existing queue timeouts, capability checks and Node-bound leases remain in force.

Fleet peer DNS reads desired-policy digests once a minute, admits on change and
once per UTC hour for drift, and waits for active work before admitting again.
For nine unchanged, healthy targets this is 216 rather than 2,592 Operations/day.
Changed policies and failed-work retries add to that estimate. Other fleet kinds
receive a batch slot before extra peer-DNS slots.

Operation discovery returns no data handles for an empty queue. The execute job
uses an empty list when that output is absent. A nonempty batch keeps a durable
checkpoint, so a fresh native process can resume after a lost effect reply.
Family discovery models and all eight execution slots are declared in `models/`
with native `reports.skip: ["@swamp/method-summary"]`; workflow definitions skip
`@swamp/workflow-summary` except `stage-*`: the existing stage driver consumes
those summaries as its successful completion signal. Other explicitly required reports remain enabled.
Do not rely on `reports: []` in a behavior type: native built-in summaries are
added separately. Retained native run history records execution errors.

On the pinned runtime, three empty family ticks previously created 15 data
versions (dispatch plus two method and two workflow summary artifacts per tick).
The native regression test requires zero versions across those ticks. At nine
30-second and four 60-second family schedules, that removes up to 475,200
version-related inodes/day from idle polling (five versions, three inodes each,
31,680 ticks/day), excluding run history and native control bookkeeping. This is
a schedule-based estimate; record catalog and filesystem samples separately.

Native global `data gc` still has no protected-version exclusion on this pin.
Its version policy can remove exact Orbit receipt versions. Keep global data GC
disabled until its candidate set is proved disjoint from the current reference
set, not just the original receipt manifest. Never change protected data metadata
to make a dry run look safe. The duplicate summary suppression does not itself
prune historical versions or claim that every data writer is bounded.
