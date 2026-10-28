# Migration workers beside serve

Orbit systemd Processes own foreground Docker containers (`docker run --rm
--init --network host`). Host networking preserves the Node's private split
DNS and WireGuard peer address, as the manual's Docker example requires.
Workers expose no listener. Docker bridge DNS cannot resolve private Orbit
names on these hosts.

The worker pool runs beside existing workloads. It does not stop CI runners or
move application Processes. Every worker uses `--concurrency auto`, which means
one dispatch slot per visible CPU, as the Swamp manual specifies.

| Node | Process | Workflow selector |
| --- | --- | --- |
| Services 17 | `migration-services` | `pool: services-local` |
| Sabre 12 | `migration-sabre` | `pool: migration` |
| Shark 13 | `migration-shark` | `pool: migration` |

The existing Services account runs Docker through `sudo -n`; this changes no
sudo policy. Sabre and Shark use their existing Docker access.

All workers also advertise their numeric `node` label. Their image pins the
same upstream release as serve. It adds curl and CA certificates, because the
Gateway HTTP adapter invokes curl and the upstream image lacks it. Each worker
mounts only its own two credential files and the public Orbit CA, plus its own
cache volume. It never mounts the serve checkout, datastore or account secrets.
Tokens expire after seven days; renew deliberately before that deadline or
remove the temporary workers. Do not copy the serve environment to workers.

## Placement

Automatic production policy and the four observation workflows select the
Services-local container: their Gateway routes authorize Services specifically.
Firewall operations select Sabre or Shark. The Gateway must have lane 0d's
worker authorization deployed with
`ORBIT_OPERATION_EXECUTOR_WORKER_NODE_IDS=12,13` before enabling their placement.
This is separate from `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID=17`; never
broaden the repository-token route. Worker Nodes need access edges to Services
17 and Gateway 14, as approved by Nick for this migration.

These workflows must remain local for now:

- Group discovery, supervision and drive; project routine/detect/package;
  maintenance-projects and maintenance-workers use native factory orchestration
  or projected datastore queries. The upstream remote context refuses
  `context.runModel()` and query projections. Placing them would fail work.
- Runtime retention needs the authoritative local datastore. Its independent
  process change is PR #63; placement must not send it a worker scratch tree.
- Operation discovery reads the repository's family registration files.
  Its composed dispatch workflow lets each family choose placement.
- Production-smoke configuration refresh reads the Services checkout.
- `maintenance-worker` and the Horizon worker step keep their exact task-VM
  target. Migration labels must not replace that binding.

The worker scratch directory is not the Services checkout. New family workflows
should use the worker-safe data API and co-located extension assets, then choose
one of the approved pools. Do not turn on `--remote-only` until the local
exceptions have been ported.

## Scheduled admission

After workers are connected, run `bin/configure-services-worker-capacity` from
the deployment host. It reuses the deploy helper's marker, protected schedule
snapshot, native pause/drain and unconditional restoration, updates only the
existing Swamp Process and checks authenticated readiness. It sets
`--max-concurrent-scheduled-runs=32` and
`--max-concurrent-webhook-runs=4` through its existing Orbit Process definition,
keeping every other argument, particularly `--dispatch-env-allow=,`.
Both require a restart. The manual defaults to one of each and documents
raising these independent limits; it does not prescribe a numeric fleet value.
32 permits nineteen nightly detections and the coordinator families to be in
flight without one long detect blocking unrelated schedules. Each workflow
still excludes its own concurrent scheduled run. Workers bound dispatched work
by their advertised slots; these settings do not create extra worker slots.
Inspect health queue age after deployment rather than hiding growing queues.

## Provision and remove

From an operator host with existing SSH access, run
`bin/configure-services-workers --token-file EXISTING_OPERATOR_TOKEN_FILE
--journal /protected/path/fleet-worker-changes.jsonl`.
Use `--node 12 --node 13` when Services is bootstrapped through its existing
Orbit-managed maintenance Process. The native server-token reveal is local-only, so the operator also needs
existing SSH access to Services. The helper runs that CLI there, under `orbit`,
with the existing account file paths; it never bypasses the reserved vault API.
Secrets travel only through captured native CLI replies and SSH stdin into mode-0600 files, never Process argv or output.
The JSONL journal records each access addition and credential name immediately,
including cleanup failures. Keep it with
`/home/nckrtl/swamp-execution/FLEET-CHANGES.md`.

To remove a worker, stop and remove only its recorded Orbit Process, revoke its
named enrollment and server token, and remove only the access edges this setup
added. Remove its two files, dedicated cache volume and locally built image
when no worker uses them. Keep CI runner containers and volumes untouched.
