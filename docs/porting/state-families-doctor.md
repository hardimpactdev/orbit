# State Families And Doctor Workstream

Cross-cutting workstream covering the family inventory, family probes, doctor
dispatcher, and the enactor/probe/doctor integration pattern. Family-specific
detail (probes, fix/adopt maps) lives in the per-family files under
`docs/porting/`.

## Family inventory

- [x] Port family inventory from the blueprint into current docs before
  implementation.
  - [x] Node-family public contract and technical `NodesProbe` contract.
  - [x] App family inventory: `docs/commands/5_app/app-doctor.md`.
  - [x] Workspace family inventory: `docs/commands/6_workspace/workspace-doctor.md`.
  - [x] Process family inventory: `docs/commands/7_process/process-doctor.md`.
  - [x] Proxy family inventory: `docs/commands/8_proxy/proxy-doctor.md`
    (probe class: `ProxyRouteProbe`).
  - [x] Firewall-rule family inventory: `docs/commands/4_firewall/firewall-doctor.md`
    (probe class: `FirewallRuleProbe`).
  - [x] Tool family inventory: `docs/commands/3_tool/tool-doctor.md`.
  - [x] Schedule family inventory: `docs/commands/9_schedule/schedule-doctor.md`.
  - [x] Global `doctor` technical contract references all eight family
    contracts.

## Doctor dispatcher coverage

`DoctorReportRunner::SUPPORTED_FAMILIES` currently dispatches verify-mode for:
`node`, `proxy`, `firewall_rule`, `tool`, `schedule`.

`AppsProbe`, `WorkspacesProbe`, and `ProcessesProbe` are implemented but not
yet wired into `DoctorReportRunner` / `DoctorScopeValidator` — adding them is a
prerequisite for `doctor --family=app|workspace|process` verify-mode use.

## Per-family doctor port status

Detailed per-family probe and fix/adopt status lives in the family files:

- [`1_node.md`](1_node.md) — node doctor probes (`NodesProbe`, WireGuard,
  platform, SSH, runtime, TLD, PHP).
- [`5_app.md`](5_app.md) — `AppsProbe`, source/PHP/PHP-FPM checks; external
  runtime artifact checks blocked.
- [`6_workspace.md`](6_workspace.md) — `WorkspacesProbe`, source/PHP/PHP-FPM;
  external runtime artifact checks blocked.
- [`7_process.md`](7_process.md) — `ProcessesProbe`, runtime backend, Supervisor
  program, restart policy, lifecycle hooks, stale unit checks (complete).
- [`8_proxy.md`](8_proxy.md) — `ProxyRouteProbe` + backend route + TLS reality;
  fix handlers for `route_missing`/`route_mismatch`/`tls_*` (partial).
- [`4_firewall.md`](4_firewall.md) — `FirewallRuleProbe` + backend UFW rules;
  fix handlers for `rule_missing`/`rule_mismatch` (partial).
- [`3_tool.md`](3_tool.md) — registry/catalog/capability probes + lifecycle
  and config/credential `--fix` handlers (capability/version fix and adopt
  outstanding).
- [`9_schedule.md`](9_schedule.md) — read-only probe + verify-mode dispatcher +
  scheduler/lock/run-history-hook fix handlers (complete).

## Enactor/probe/doctor integration pattern

- [x] Port enactor/probe/doctor integration pattern with focused tests before
  broader command migration depends on it.
  - Current pattern: family probes emit `DriftEntry`, `DoctorReportRunner`
    converts unresolved drift into issues, completed fix actions suppress
    resolved issues, unsupported issue/mode pairs become skipped actions, and
    failed actions keep drift visible.
  - Current tests: `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php`.
