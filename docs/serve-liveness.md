# Serve liveness and inode capacity

Node 209 runs the independent `orbit-swamp-liveness.timer` once a minute. It reads serve's public loopback health and the Gateway's peer-authorized claimable summary. It needs no token, vault, datastore or worker cache access. Root-owned systemd controls serve392 directly; the Process API cannot recover the orchestrator it depends on.

The probe restarts serve after health has failed for three minutes, or claimable work has remained unchanged for three minutes while its oldest item is more than three minutes old. A draining backlog resets the progress timer. An unavailable Gateway read does not establish a stall. Startup has a three-minute grace period; restarts have a ten-minute cooldown, persisted before the restart command. The probe never changes schedules or Operations. Planned unit-control loads stop this timer and its current probe, then restore its previous timer state after readiness; dispatch schedules remain active.

The datastore is on Node 209's root filesystem. The probe emits a journal warning below 10% free inodes and a critical alert below 2%. Below 1%, it reports inode exhaustion and suppresses automatic restart: restarting cannot restore storage capacity. Check both `df -h /` and `df -i /` when a write returns `ENOSPC`.

Install the checked-in files through the existing independent host-control route:

```sh
sudo install -D -m 0755 bin/swamp-liveness.py /usr/local/libexec/orbit-swamp-liveness.py
sudo install -m 0644 systemd/orbit-swamp-liveness.service /etc/systemd/system/
sudo install -m 0644 systemd/orbit-swamp-liveness.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now orbit-swamp-liveness.timer
sudo systemctl start orbit-swamp-liveness.service
journalctl -u orbit-swamp-liveness.service -n 5 --no-pager
```

The service uses root-owned private state under `/var/lib/orbit-swamp-liveness`, a read-only filesystem sandbox, no home access and `NoNewPrivileges`. Its only mutation outside that state directory is the fixed serve unit restart through systemd. Roll back by disabling the timer and removing these two unit files and the installed script. Preserve its state and journal for diagnosis. This does not grant worker or package processes any new privilege.

Native metadata retention is enabled in `.swamp.yaml`: runs keep seven days; finished evaluated snapshots and outputs keep one day. Native bookkeeping keeps a one-hour grace for ended leases/dispatches and stale disconnected workers; active leases, waiting dispatches and worker-referenced tokens survive. The `data` key remains absent. This prevents metadata cleanup from deleting exact data versions referenced by durable receipts. The native fire-record reaper already keeps four hours and runs every ten minutes. Keep the old online planner paused. See [compaction](services-compaction.md) for protected data cleanup and backup rules.

For emergency metadata cleanup, stop serve with independent unit control, make and verify a fresh `.swamp` archive, verify the protected receipt hashes, then run `swamp run gc --dry-run --json` with the configured policy. Review its counts, run `swamp run gc --force --json`, and verify those hashes again. Use the existing account files without printing them. No `data gc` is part of this procedure. Load the approved source, start serve and verify schedule pause states plus a real claim. Record before/after inode counts, the backup checksum, GC totals and claim time in `FLEET-CHANGES.md`.

Operation families use 30-second schedules; database, dependency merge, integrations and firewall use 60 seconds. A tick admits at most eight parallel claim slots and four of one kind, visiting oldest kinds first. Eight fixed, short slot model names per family avoid Swamp's model lock and name-length failures. These bounds apply to discovery hints; the Gateway still atomically selects each Operation. Existing queue timeouts, capability checks and Node-bound leases remain in force.

Fleet peer DNS reads desired-policy digests once a minute, admits on change and
once per UTC hour for drift, and waits for active work before admitting again.
For nine unchanged, healthy targets this is 216 rather than 2,592 Operations/day.
Changed policies and failed-work retries add to that estimate. Other fleet kinds
receive a batch slot before extra peer-DNS slots.
