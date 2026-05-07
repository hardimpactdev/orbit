# 6_workspace — Workspace Workstream

Detail file for the workspace command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/6_workspace/`.

Read, step-policy, history/log, removal, creation/setup, and the current
workspace doctor scope are ported.

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
- [x] `workspace:new` — atomic intent creation + remote provisioning.
  Creates the workspace row with validation (slug format, reserved name,
  uniqueness, supported PHP version), performs a gateway→app-node SSH
  preflight, creates a detached git worktree from `--base`, runs the
  `workspace:setup` convergence pipeline, and reports retryable downstream
  drift in `success.meta.warnings[]`. Pest:
  `tests/Feature/Commands/Workspaces/WorkspaceNewCommandTest.php`,
  `tests/Feature/Http/Api/WorkspaceStoreControllerTest.php`. E2E:
  `tests/E2E/WorkspaceNewTest.php`;
  `composer test:e2e:docker -- --filter='creates and sets up a workspace|sets up an existing workspace path'`.
- [x] `workspace:setup` — lifecycle implementation complete. Command resolves workspace identity (by name, path, or CWD), validates absolute paths, handles control/gateway/app caller roles (control/app forward to gateway API), and orchestrates 6 phases: registry convergence, proxy routing, FPM pool artifact enactment, setup step execution with hash-based skip logic, process runtime unit enactment, and HTTP readiness probe. Returns `set_up`/`adopted`/`converged` action. Pest: `tests/Feature/Commands/Workspaces/WorkspaceSetupCommandTest.php`, `tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php`. E2E: `tests/E2E/WorkspaceSetupTest.php`; `composer test:e2e:docker -- --filter='creates and sets up a workspace|sets up an existing workspace path'`.

## Family doctor

- [x] `WorkspacesProbe` — current workspace doctor scope is deliberately
  narrowed to gateway record completeness, parent-app eligibility, effective
  PHP inheritance, source/parent-app path policy, PHP runtime availability,
  and PHP-FPM pool config drift. Runtime unit drift remains owned by
  `process`; workspace-owned route drift remains owned by `proxy`; broad
  stale-artifact scanning and arbitrary path adoption stay deferred until
  deploy/runtime ownership introduces a durable inventory source. Pest:
  `tests/Unit/Services/Workspaces/WorkspacesProbeTest.php`,
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`. E2E:
  `tests/E2E/Ephemeral/WorkspacesDoctorTest.php`;
  `ORBIT_E2E_CHECKOUT_CACHE=0 composer test:e2e:docker -- --filter='reports workspace source drift'`.

## Foundations

- [x] Workspace schema, models, and abstraction seed.
- [x] Saloon gateway requests/responses under `App\Http\Gateway\…\Workspaces`.
- [x] Server-side SSE progress foundation (`ProgressReporter`,
  `Sse`/`Null` reporters, event emitter, response factory) — future
  `workspace:new` / `workspace:setup` / `workspace:remove` lifecycle slices
  reuse this; do not invent another stream shape.
