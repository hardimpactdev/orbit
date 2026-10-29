# Serve execution capacity

Swamp serve runs on the dedicated four-core, 8 GB Node 209. All long-lived workers now run there as independent native systemd Processes. Current placement, credential scope, admission limits and recovery procedures are in [Workers on the Swamp Node](services-workers.md).

The initial retention incident on 2026-10-28 pegged one JavaScript thread while most host capacity was idle. Moving the datastore and adding workers alone did not remove the expensive in-process dependency methods. Those methods now use a separate native process on the authoritative host, launched by a placed worker; see [Dependency methods](native-host-methods.md). Native factory coordinators retain their local context.

Online runtime-retention is paused because its planner removed nothing. Native GC remains disabled because it cannot protect Orbit's exact receipt references. Disk recovery uses the backup-first offline procedure in [Services compaction](services-compaction.md). Do not re-enable online planning or GC as a worker-capacity change.

Measure serve and worker CPU over the same 60-second interval, verify unchanged PIDs, record RSS and host available memory, and inspect worker queues. Report CPU as a percentage of one core. A restart resets catch-up age, and idle workers do not establish peak scan capacity. Keep workload changes and proxy repairs separate so the comparison remains useful.
