# Doctor process restorer retained Incus proof

- Candidate: `948142ba54fb3d74b8cd3be33a5012c66b247a73`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-56112f` (`operator_gateway_app-dev`) on Beast
- Beast SSH route: `nckrtl@192.168.6.20`
- Solo terminal: `solo://proj/27/process/2281`
- Runtime checkout: `/home/orbit/orbit-run`
- Runtime launcher: `/home/orbit/orbit-run/apps/cli/orbit`

## Candidate identity

The local candidate and retained gateway checkout had the same SHA-256 values:

- `DoctorProcessRestorer.php`: `544fac9b4956f923f1afe69beda04ecba629109c8a8a0541b8ce62c636c11406`
- `DoctorReportRunner.php`: `a6695c85d882639284fc3e5a929faa4aeed574090c7486b440d83c6b218118b5`
- `NodeProcessResolver.php`: `b0eafcb89912ed5956d1891d1c89aabe137fc02aff67060e538416343c05d9d1`

## Runtime proof

The Solo terminal used the source-mounted candidate to add the node-owned
`doctor-restorer-proof` systemd process on `app-dev-1`. The process started and
the unit was active and enabled.

The terminal then stopped the process, removed
`/etc/systemd/system/doctor-restorer-proof.service`, reloaded systemd, and
confirmed `UNIT_MISSING`.

It ran:

```text
./apps/cli/orbit doctor --node=app-dev-1 --family=process --key=process.runtime_unit_missing --restore --json
```

Doctor returned exit code `0`, `healthy=true`, `fixed=1`,
`stop_reason=converged`, and one completed action:

```text
Restored process runtime unit doctor-restorer-proof.
```

The node then reported `UNIT_RESTORED` and the systemd unit was enabled. A
fresh Doctor verify returned exit code `0`, `healthy=true`, zero issues, and
zero actions. A second restore returned exit code `0`, `fixed=0`, zero actions,
and `stop_reason=no_restorable`, which proves the restore is idempotent.

## Cleanup

`process:remove --force` removed the process and runtime unit. The node
reported `CLEANUP_COMPLETE`. Releasing topology `dev-56112f` reaped the
operator, gateway, and app-dev instances.
