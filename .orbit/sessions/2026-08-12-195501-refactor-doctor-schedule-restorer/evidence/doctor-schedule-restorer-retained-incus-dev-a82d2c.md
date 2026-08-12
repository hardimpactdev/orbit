# Doctor Schedule Restorer Retained Incus Proof

- Candidate: `bcf9cfc6d5db87feb9bb53162516f6d8d82fc426`
- Topology: `dev-a82d2c` (`operator_gateway`)
- Solo terminal: project `53`, process `2325`
- Incus host: `beast` at LAN address `192.168.6.20`, SSH user `nckrtl`
- Runtime checkouts: `/home/orbit/orbit-run` on the operator and gateway

## Candidate identity

The source files in the gateway checkout matched the candidate worktree:

- `DoctorGatewayScheduleRestorer.php`: `cb6561e3ef78da988eba28f740e88e8a7dbd14b2abcfb79f03b0f3bedf68f85c`
- `DoctorScheduleRestorer.php`: `dfe51f3417b40dc188acdda7e9373af3501ddcde86e5db9abf952077ac064dc9`

## Fixture preparation

The retained topology uses source-mounted gateway API containers and skips the
normal gateway service install. It therefore had no canonical gateway stack
file. The proof generated that file with the production
`GatewaySwarmStackRenderer` and `GatewaySwarmManager` before running restore.
No product source file was changed in the topology.

The initial schedule check reported:

- `schedule.runtime_backend_unavailable` as blocked inspection.
- `schedule.runtime_hibernator_missing` as genuine, restorable drift.

## Restore result

Command:

```text
orbit doctor --node=gateway --family=schedule --key=schedule.runtime_hibernator_missing --restore --json
```

Observed result:

- Exit code: `0`
- Doctor mode: `restore`
- Healthy: `true`
- Fixed: `1`
- Passes: `1`
- Stop reason: `converged`
- Action family: `schedule`
- Action node: `gateway`
- Action key: `schedule.runtime_hibernator_missing`
- Action mode: `restore`
- Action status: `completed`
- Action summary: `Repaired gateway Orbit Scheduler.`

The next exact-key verify returned exit code `0`, `healthy: true`, zero issues,
and zero actions:

```text
orbit doctor --node=gateway --family=schedule --key=schedule.runtime_hibernator_missing --json
```

This proves that the extracted schedule restore entry point selected the
gateway node, called the schedule fixer, preserved the exact action output, and
let the bounded restore loop converge.

## Retained fixture limitation

Beast reused `orbit-gateway:prepared-current` from an older image build. That
image does not define the newer `orbit-runtime-hibernator` Artisan command.
After the missing service configuration was restored, the full schedule check
therefore reported the separate key `schedule.runtime_hibernator_stopped`.
The exact missing-configuration contract above passed. This stale prepared
image is a separate retained-topology determinism problem; this proof does not
claim that the old image can run the newer command.

## Cleanup

`composer e2e:incus -- --stop --id=dev-a82d2c --json` released both VMs. A
direct LAN query through `nckrtl@192.168.6.20` found no remaining instance name
containing `dev-a82d2c`.
