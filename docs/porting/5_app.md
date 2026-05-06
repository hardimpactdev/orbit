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
- [~] `app:agent-ide` — gateway-local + Saloon forwarding + interactive.
  E2E `tests/E2E/AppAgentIdeTest.php`.
  - [ ] Workspace cleanup planning (waits for workspace schema/removal).
- [ ] `app:prune` — ready for implementation. Discovers stale workspace
  intent and delegates to `workspace:remove`.

## Family doctor

`AppsProbe` foundation ported (record completeness, owning-node eligibility,
agent-IDE default, source/document-root reality, PHP runtime, PHP-FPM
config). Outstanding:

- [~] External app runtime artifact checks: runtime configuration,
  production policy, deployment health, stale app artifacts. No external
  blocker remains; either port the needed deploy/runtime intent as part of the
  app-doctor slice, or deliberately narrow `app-doctor` to the currently
  implemented checks with documented rationale and paired tests.

## Open decisions

- Legacy helpers (`app:link`, `app:secure`, `app:status`, `app:sync`,
  scheduler commands) — port docs or formally retire?

## Foundations the family depends on

- [x] App schema and Eloquent model (`app/Models/App.php`).
- [x] Saloon gateway requests/responses under `App\Http\Gateway\…\Apps`.
- [x] Gateway-owned `RemoteShell` (`app/Services/RemoteShell/SshRemoteShell.php`)
  for app write enactment.
- [x] App abstraction reference (`docs/abstractions/5_app.md`).
