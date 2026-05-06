# 6_workspace — Workspace Workstream

Detail file for the workspace command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/6_workspace/`.

Read, step-policy, history/log, and `workspace:remove` surfaces are ported.
Workspace creation/setup are the remaining lifecycle implementation slices.

## Commands

- [x] `workspace:list` — gateway-local + Saloon forwarding + access-policy.
  `lane=none` (fast registry read).
- [x] `workspace:show` — named lookup + interactive prompts + cwd
  resolution + Saloon forwarding via path-resolution request.
  `lane=none`.
- [x] `workspace:remove` — Phase A intent deletion + Saloon forwarding +
  best-effort node-side cleanup warnings. E2E
  `tests/E2E/WorkspaceRemoveTest.php`.
- [x] `workspace:history` — registry history + filters + pagination + Saloon
  forwarding. E2E `tests/E2E/WorkspaceHistoryTest.php`.
- [x] `workspace:log` — stored-output read + access-policy + Saloon
  forwarding. E2E `tests/E2E/WorkspaceLogTest.php`.
- [x] `workspace-setup-step:add|list|remove` and
  `workspace-teardown-step:add|list|remove` — gateway-owned policy
  CRUD + Saloon forwarding + destructive consent + order compaction.
  E2E `tests/E2E/WorkspaceStep{Add,List,Remove}Test.php`.
- [ ] `workspace:new` — ready for lifecycle implementation.
- [ ] `workspace:setup` — ready for lifecycle implementation.

## Family doctor

`WorkspacesProbe` ported (record completeness, parent-app eligibility,
effective PHP inheritance, source/parent-app path policy, PHP runtime,
PHP-FPM config). Outstanding:

- [~] External workspace runtime artifact checks: runtime configuration,
  stale artifacts, adoption hints. Implement alongside the workspace
  lifecycle/enactor slice that defines runtime config, managed ownership,
  stale artifact inventory, and adoption scan inputs, or deliberately narrow
  `workspace-doctor` to source + PHP + FPM reality with documented rationale
  and paired tests.

## Foundations

- [x] Workspace schema, models, and abstraction seed.
- [x] Saloon gateway requests/responses under `App\Http\Gateway\…\Workspaces`.
- [x] Server-side SSE progress foundation (`ProgressReporter`,
  `Sse`/`Null` reporters, event emitter, response factory) — future
  `workspace:new` / `workspace:setup` / `workspace:remove` lifecycle slices
  reuse this; do not invent another stream shape.
