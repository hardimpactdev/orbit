# Serve execution capacity

Swamp serve runs on the dedicated four-core, 8 GB Node 209 with `SWAMP_REMOTE_ONLY=1` and `--remote-only`. It relays execution to placed workers. Scheduled admission uses Swamp's default of one run. All long-lived workers run as independent native systemd Processes. Current placement, credential scope, admission limits and recovery procedures are in [Workers on the Swamp Node](services-workers.md).

Enable `--hot-reload` in the same required startup-flags restart. Source loads use native reload when the runtime supports that change; flags and binary changes require a full restart. Keep remote-only and scheduled default1 in both the saved Process command and host unit. `bin/serve-command.py` builds that command without changing webhook admission or other arguments. The loader owner verifies pause states, protected files, configuration, workflow IDs and workers around each load.

The October30 audit found unplaced group discovery/supervision, group execution, worker maintenance and dependency-merge steps. They now select `pool=migration,node=209`. The pinned worker cannot perform `context.runModel()`; these coordinators use the existing worker-owned native CLI lifetime for nested model methods. Native DAG decisions, approvals and signal bookkeeping remain Swamp control-plane functions. Missing placement refuses extension execution instead of running its body in serve.

The initial retention incident on 2026-10-28 pegged one JavaScript thread while most host capacity was idle. Moving the datastore and adding workers alone did not remove the expensive in-process dependency methods. Those methods now use a separate native process on the authoritative host, launched by a placed worker; see [Dependency methods](native-host-methods.md). Placed factory coordinators use worker query capabilities; projections are applied only to the permitted returned records.

Swamp owns data and history collection through its native retention settings. The old runtime-retention planner and offline compactor are removed. See [native retention](native-retention.md) for the policy and first-collection checks.

Measure serve and worker CPU over the same 60-second interval, verify unchanged PIDs, record RSS and host available memory, and inspect worker queues. Report CPU as a percentage of one core. A restart resets catch-up age, and idle workers do not establish peak scan capacity. Keep workload changes and proxy repairs separate so the comparison remains useful.
