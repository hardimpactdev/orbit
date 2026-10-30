# Workers on the Swamp Node

Serve and all three long-lived workers run on the dedicated Swamp Node 209. Each worker is an independent native systemd Process under `orbit`, uses the same pinned Swamp binary as serve and connects to `wss://swamp.orbit`. Workers expose no listener. Each uses `--concurrency auto`, which advertises four slots on this four-core Node.

| Process | Enrollment name | Labels |
| --- | --- | --- |
| 434 | `swamp209-migration-a` | `pool=migration`, `node=209` |
| 435 | `swamp209-migration-b` | `pool=migration`, `node=209` |
| 436 | `swamp209-services` | `pool=services-local`, `node=209` |

Worker credentials live in `/home/orbit/.config/swamp/workers/<name>/{enrollment,server}`, mode 0600. Each worker has its own `/home/orbit/.cache/swamp-workers/<name>` directory. Enrollment and server credentials expire after seven days; renew them deliberately before the deadline recorded in `FLEET-CHANGES.md`. Process argv contains only paths. Never copy a credential into a Process definition, Operation or log.

The temporary `migration-sabre`, `migration-shark` and `migration-services` workers are retired. Their Processes, six credentials, worker-only files, caches and unused image tags were removed after draining. CI containers and unrelated host resources were preserved. The old `configure-services-workers` helper describes that retired topology and must not be used to renew these native workers.

## Placement and authority

Gateway configuration is `ORBIT_OPERATION_EXECUTOR_WORKER_NODE_IDS=209` and `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID=209`. The former authorizes generic Operation execution; the latter independently limits short-lived repository-token requests. Neither label nor membership in the migration pool grants control-worker authority, vault access or Node execution keys. Node-local steps run as the Node record’s configured user with its existing passwordless sudo. Package tools keep their separate sandbox account without sudo. New families use native workflows and the shared [direct-start contract](direct-operations.md).

The nineteen project detect/routine pairs, maintenance-projects and dependency package/upgrade methods select `pool=migration`, `node="209"`, with a 300-second queue timeout. Their heavy methods use the [native host adapter](native-host-methods.md), because remote `context.runModel()` is unavailable in the pinned release. The worker starts the original method in a separate local process, with the authoritative checkout and datastore. Node 209 supplies git, gh, Node/npm, Bun, PHP and Composer. pnpm lock inventory does not invoke pnpm. Package commands use disposable trees and their existing credential-free environment.

Light coordinators stay in serve: group/subtask drivers, stages, retention/prune and maintenance-workers. Each independently scheduled Operation family discovers its own claimable kinds on a Node 209 worker; dependency merge stays local for its private configuration refresh. Families use separate discovery behavior instances and 60-second placement timeouts. Dependency-routine only starts a supervision workflow, so that step stays local too. Nested package supervision, upgrade assertions/approval and Horizon's verification/backlog steps clear inherited placement. Horizon detection and task-group maintenance retain their exact enrollment-bound task-VM worker target.

Automatic production policy and observation select `pool=services-local`. Each Operation family claims and executes in one worker invocation. The claiming Node remains the lease owner for heartbeat, run and completion. Missing workers queue or fail within their deadline; they never cause local effect execution.

## Admission and recovery

Serve uses the native scheduled-run default, `--max-concurrent-scheduled-runs=1`. Webhook admission and worker capacity are configured separately. Scheduled admission does not create worker slots; each workflow still excludes its own overlapping scheduled runs. The previous override permits up to 32 simultaneous scheduled runs. Restoring the native default is a controlled canary for the observed health and WebSocket stalls; improvement must persist beyond restart before attributing the stalls to concurrency. Validate any increase with localhost health, configured Gateway health and native history latency under the real scheduled workload. Host-wide CPU and available memory alone do not show event-loop headroom. Keep worker capacity, retention and workflow pause state independent of this limit.

Control serve and these workers through the existing independent unit-control route. Never stop them through the Swamp-backed Process execution path. Drain dispatches before stopping or updating a worker. After a load, verify the native worker roster, bounded empty claims, observation and automatic policy runs, then restore exact schedule states. Subtasks-driver remains intentionally paused. The removed runtime-retention workflow is not restored. The datastore, native vault definitions and audit belong in consistent backups; external vault keys use the separate recovery-custody process.

Record Process IDs, credential names/expiry, labels, Gateway env backups and every temporary fleet change in `/home/nckrtl/swamp-execution/FLEET-CHANGES.md`. Compare matched 60-second CPU/RSS samples and available memory after moving real workloads. Three idle workers fitting in memory does not prove capacity for simultaneous package scans.
