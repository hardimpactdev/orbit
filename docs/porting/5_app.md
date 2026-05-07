# 5_app — App Workstream

Detail file for the app command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/5_app/`.

## Commands

- [x] `app:list` — gateway-local + Saloon forwarding. E2E `tests/E2E/AppListTest.php`.
- [x] `app:show` — gateway-local + Saloon forwarding. E2E `tests/E2E/AppShowTest.php`.
- [x] `app:new` — gateway-local + Gateway API + control forwarding +
  interactive + progress-tree human renderer + retryable runtime warnings +
  PHP-FPM/proxy/process artifact convergence. E2E `tests/E2E/AppNewTest.php`
  (Docker feature) and `tests/E2E/AppNewProvisioningTest.php` (Incus
  provision for real PHP-FPM/Caddy/process artifact convergence).
- [x] `app:register` — adoption/convergence + Saloon forwarding +
  interactive + production-domain retry warnings. E2E
  `tests/E2E/AppRegisterTest.php` (Docker feature + provisioning refresh).
- [x] `app:root` — gateway-local intent update + Saloon forwarding +
  interactive + PHP-FPM/proxy refresh. E2E `tests/E2E/AppRootTest.php`.
- [x] `app:remove` — destructive intent removal + Saloon forwarding +
  interactive + node-side cleanup warnings. E2E `tests/E2E/AppRemoveTest.php`.
- [x] `app:agent-ide` — gateway-local + Saloon forwarding + interactive +
  workspace cleanup during adapter switch. Captures previous effective adapter
  before writing intent, then prunes stale workspaces managed by the previous
  adapter via `PruneAppWorkspaces`. Supports `--force` / `force=true` for
  destructive consent bypass. Interactive mode prompts for consent when stale
  workspaces are detected. E2E `tests/E2E/AppAgentIdeTest.php`.
- [x] `app:prune` — stale workspace discovery and removal implemented. Compares
  gateway-tracked workspaces with adapter-reported workspaces (via new
  `AgentIdeMessageAdapter::workspaces()` contract method), identifies stale
  workspaces, and delegates removal to `RemoveWorkspace` action. Supports
  `--dry-run` for preview. Pest:
  `tests/Feature/Commands/Apps/AppPruneCommandTest.php`,
  `tests/Feature/Actions/Apps/PruneAppWorkspacesActionTest.php`.

## Family doctor

`AppsProbe` foundation ported (record completeness, owning-node eligibility,
agent-IDE default, source/document-root reality, PHP runtime, PHP-FPM
config). Intentionally narrowed:

- [-] External app runtime artifact checks (runtime configuration, production
  policy, deployment health, stale app artifacts) are deferred until the
  `10_deploy` family is ported. The current `AppsProbe` checks cover the
  app lifecycle surface that exists today: registry completeness, node
  eligibility, path/document-root reality, PHP runtime availability, and
  PHP-FPM config convergence. Paired tests:
  `tests/Unit/Services/Apps/AppsProbeTest.php`.

## Open decisions

- Legacy helpers (`app:link`, `app:secure`, `app:status`, `app:sync`,
  scheduler commands) — port docs or formally retire?

## Foundations the family depends on

- [x] App schema and Eloquent model (`app/Models/App.php`).
- [x] Saloon gateway requests/responses under `App\Http\Gateway\…\Apps`.
- [x] Gateway-owned `RemoteShell` (`app/Services/RemoteShell/SshRemoteShell.php`)
  for app write enactment.
- [x] App abstraction reference (`docs/abstractions/5_app.md`).
