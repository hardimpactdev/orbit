# State Families And Doctor Workstream

Cross-cutting coverage for the family inventory, family probes, doctor
dispatcher, and the enactor/probe/doctor pattern. Per-family probe + fix/adopt
detail lives in each family file under `docs/porting/`.

## Family inventory

All eight family doctor inventories are ported as command/family doctor
contracts under `docs/commands/<n>_<family>/`. The global `doctor` technical
contract references every family contract.

## Doctor dispatcher coverage

`DoctorReportRunner::SUPPORTED_FAMILIES` currently dispatches verify-mode for
`node`, `app`, `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, and
`schedule`. `AppsProbe`, `WorkspacesProbe`, and `ProcessesProbe` are wired
into `DoctorReportRunner` / `DoctorScopeValidator` for verify-mode use.

Family-owned `--fix` / `--adopt` handlers for `app`, `workspace`, and
`process` remain separate family implementation work; verify-mode dispatch does
not imply those convergence actions are complete.

Probe class names follow the `<Domain>Probe` shape; the proxy and
firewall-rule probes are named `ProxyRouteProbe` and `FirewallRuleProbe`.

## Pattern

Family probes emit `DriftEntry`. `DoctorReportRunner` converts unresolved
drift into issues, suppresses resolved issues after completed fix actions,
turns unsupported issue/mode pairs into skipped actions, and keeps drift
visible when actions fail. Tests:
`tests/Unit/Services/Doctor/DoctorReportRunnerTest.php`.
