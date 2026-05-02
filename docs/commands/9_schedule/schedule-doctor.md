# Schedule Doctor

[Back to Schedule commands.](README.md)

`doctor --family=schedule` verifies whether gateway schedule intent still
matches node timer and service reality. It covers Orbit-owned schedules only.

The schedule family owns these facts:

- gateway-owned schedule rows: scope, target, interval, timezone, execution
  source, enabled state, and backend metadata needed to identify the enacted
  recurring artifacts;
- managed timer and service artifacts rendered from those rows;
- run-history capture hooks needed for schedule observability;
- drift between gateway intent and node schedule backend reality;
- adoption facts for explicitly selected observed schedule artifacts that can
  safely become Orbit-owned schedule intent.

App source, app PHP-FPM, process units, proxy routes, tools, firewall rules, and
node reachability belong to their own families. The schedule family verifies
recurring timer/service artifacts and run-history hooks, not application health.

## Probe Layers

The schedule probe reads gateway schedule intent and checks these layers:

1. **Registry intent:** every selected schedule has valid scope, target,
   interval, timezone, execution source, enabled state, and backend identity
   metadata.
2. **Target eligibility:** the app, node, or Orbit maintenance target resolves
   and is visible to the caller.
3. **Node eligibility:** the target node resolves to a visible active Ubuntu
   gateway or app node with schedule capability.
4. **Timer presence:** the expected timer artifact exists when gateway intent
   says the schedule is enabled.
5. **Service presence:** the expected service artifact exists when gateway
   intent says the schedule is enabled.
6. **Artifact shape:** observed timer and service content matches the expected
   interval, timezone, execution source, target context, environment, and
   enabled state.
7. **Run-history hook:** schedule output capture and exit-status recording
   hooks exist when the command contract expects durable run history.
8. **Extra artifact ownership:** Orbit-owned timer or service artifacts without
   matching gateway intent are reported as extra schedule drift.
9. **Adoption scope:** during `--adopt`, explicitly selected observed schedule
   artifacts may be inspected for compatible schedule facts.

Observed timer/service artifacts without Orbit ownership markers are unmanaged
node reality by default. They are reported as drift only when the operator
requested an explicit adoption scope.

## Schedule Issue Codes

| Code | Detected when |
| --- | --- |
| `schedule.record_incomplete` | A selected gateway schedule lacks scope, target, interval, timezone, execution source, enabled state, or backend identity metadata required for comparison. |
| `schedule.target_invalid` | The schedule points at a missing, unauthorized, inactive, unsupported, or role-incompatible target. |
| `schedule.unit_missing` | Gateway intent expects timer/service artifacts, but one or more artifacts are absent from node reality. |
| `schedule.unit_mismatch` | Managed timer/service artifacts exist but differ from gateway intent. |
| `schedule.unit_extra` | An Orbit-owned timer or service artifact has no matching gateway schedule row, or an explicitly selected observed artifact has no matching gateway schedule row during adoption scope. |
| `schedule.run_history_hook_missing` | The schedule exists, but the expected output/exit-status capture hook is missing. |
| `schedule.run_history_hook_mismatch` | The schedule run-history hook exists but differs from gateway intent. |

## Schedule Fix Map

| Code | `--fix` behavior |
| --- | --- |
| `schedule.unit_missing` | Recreate missing timer and service artifacts from gateway intent when the target node is reachable and eligible. |
| `schedule.unit_mismatch` | Replace managed timer and service artifacts with the gateway-intended artifacts. |
| `schedule.unit_extra` | Remove the extra timer/service artifact only when it carries Orbit ownership metadata or can otherwise be tied safely to absent gateway intent. |
| `schedule.run_history_hook_missing` | Recreate the run-history hook for the selected schedule. |
| `schedule.run_history_hook_mismatch` | Replace the run-history hook with the gateway-intended hook. |

`--fix` does not handle `schedule.record_incomplete` or
`schedule.target_invalid`.

## Schedule Adopt Map

| Code | `--adopt` behavior |
| --- | --- |
| `schedule.unit_extra` | Create a gateway schedule row only when the operator selected a specific node and timer/service pair, the target can be resolved, and the observed artifacts can be represented in Orbit schedule fields. |
| `schedule.unit_mismatch` | Update gateway intent only when the operator selected the specific schedule and the observed artifacts can be represented without changing ownership scope. |

`--adopt` does not scan arbitrary hosts, adopt app/process/systemd services as
schedules, infer app ownership from working directories, or adopt failed run
history into schedule intent.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ScheduleFamilyDoctorContractTest.php` | Schedule-family dispatch, probe-layer selection, schedule issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering as it affects schedule probes. |
| `tests/Unit/Services/Schedules/ScheduleProbeTest.php` | In-memory schedule probe diff behavior for registry intent, target eligibility, node eligibility, missing units, mismatched units, extra units, run-history hooks, and selected extra artifacts in adoption scope. |
| `tests/E2E/Read/ScheduleDoctorTest.php` | Real read-only `doctor --family=schedule --json` against nodes with managed schedules. |
| `tests/E2E/Ephemeral/ScheduleDoctorFixTest.php` | Real `doctor --family=schedule --fix` repair of safe managed schedule drift. |
| `tests/E2E/Ephemeral/ScheduleDoctorAdoptTest.php` | Real `doctor --family=schedule --adopt` for compatible selected observed schedule adoption. |
