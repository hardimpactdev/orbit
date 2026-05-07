# 11_operation — Operation Workstream

Detail file for the operation command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/11_operation/`.

## Commands

- [x] `update` — JSON renderer + tree-style human progress + split contract
  tests + activity contract. Pest under `tests/Feature/Commands/Operations/`.
- [x] `update:all` — JSON + per-installation tree + control-node exclusion
  + split contract tests + activity contract. Pest under
  `tests/Feature/Commands/Operations/`.
  - [x] Caller-role and gateway authorization contract.
    `tests/Feature/Commands/Operations/UpdateAllCommandTest.php`.
  - [x] Intent-source split: control reads node intent from the Gateway API,
    gateway reads local state. `tests/Feature/Http/Api/UpdateAllControllerTest.php`.
  - [x] Execution topology: gateway-owned `RemoteShell` as the only legal
    SSH edge — control callers must not SSH to other nodes.
    `tests/Feature/Commands/UpdateAllCommandTest.php`.
- [x] `profile` — gateway/app forwarding + caller/gateway-origin fallback +
  cwd inference + Toolbar human renderer. Pest under
  `tests/Feature/Commands/Operations/`; Docker feature E2E
  `tests/E2E/ProfileTest.php`.
  - [x] Workspace cwd inference using the current workspace schema/models.
    Gateway callers resolve workspace from absolute path selectors; when cwd
    falls inside a workspace path, the profile uses the workspace URL and
    includes the workspace name in the target payload. Pest:
    `tests/Feature/Commands/Operations/ProfileCommandTest.php`.
- [x] `doctor` — verify-mode dispatcher + family probes. Currently
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
  - [x] Operation-owned mode orchestration is complete: unsupported family
    actions are surfaced as skipped action records, and supported family
    actions are owned by each family workstream. Remaining family-specific
    fix/adopt expansion must be tracked in that family, not as an operation
    command blocker.
