# 11_operation — Operation Workstream

Detail file for the operation command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/11_operation/`.

## Commands

- [x] `update` — JSON renderer + tree-style human progress + split contract
  tests + activity contract. Pest under `tests/Feature/Commands/Operations/`.
- [~] `update:all` — JSON + per-installation tree + control-node exclusion
  + split contract tests + activity contract. Pest under
  `tests/Feature/Commands/Operations/`.
  - [ ] Caller-role and gateway authorization contract.
  - [ ] Intent-source split: control reads node intent from the Gateway API,
    gateway reads local state.
  - [ ] Execution topology: gateway-owned `RemoteShell` as the only legal
    SSH edge — control callers must not SSH to other nodes.
- [~] `profile` — gateway/app forwarding + caller/gateway-origin fallback +
  cwd inference + Toolbar human renderer. Pest under
  `tests/Feature/Commands/Operations/`; Docker feature E2E
  `tests/E2E/ProfileTest.php`.
  - [ ] Workspace cwd inference (blocked on workspace schema/models).
- [~] `doctor` — verify-mode dispatcher + family probes. Currently
  `DoctorReportRunner::SUPPORTED_FAMILIES` dispatches `node`, `app`,
  `workspace`, `process`, `proxy`, `firewall_rule`, `tool`, `schedule`.
  Generic `--fix` / `--adopt`
  orchestration reaches family dispatch and records unsupported actions as
  skipped.
  - [x] Verify-mode dispatch for `app`, `workspace`, and `process` —
    wired through `DoctorReportRunner` / `DoctorScopeValidator`. Focused
    coverage:
    `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`,
    `tests/Feature/Http/Api/DoctorRunControllerTest.php`, and
    `tests/Unit/Services/{Apps,Workspaces,Processes}/*ProbeTest.php`.
  - [!] Streaming progress and family-owned `--fix` / `--adopt` handlers
    remain blocked per family; see
    [`state-families-doctor.md`](state-families-doctor.md).
