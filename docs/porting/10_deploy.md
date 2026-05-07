# 10_deploy — Deploy Workstream

Detail file for the deploy command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/10_deploy/`.

## Commands

- [x] `deploy:step-add` — gateway-owned deployment policy write for
  production apps, ordered insertion, timeout and retention metadata, typed
  Gateway API forwarding for non-gateway callers. Pest:
  `tests/Feature/Commands/Deploy/DeployCommandTest.php`.
- [x] `deploy:step-list` — ordered production app pipeline read from gateway
  state, typed Gateway API forwarding. Pest:
  `tests/Feature/Commands/Deploy/DeployCommandTest.php`.
- [x] `deploy:step-remove` — destructive policy removal by id/title with
  order compaction and preserved run history, typed Gateway API forwarding.
  Pest: `tests/Feature/Commands/Deploy/DeployCommandTest.php`.
- [x] `deploy:run` — creates durable run history, executes ordered steps on
  the app-owning node through the gateway `RemoteShell` edge, captures stdout,
  stderr, exit code and timing, and updates latest deployment status. Pest:
  `tests/Feature/Commands/Deploy/DeployCommandTest.php`.
- [x] `deploy:history` — newest-first stored deployment run history with
  limit cap metadata, typed Gateway API forwarding. Pest:
  `tests/Feature/Commands/Deploy/DeployCommandTest.php`.
- [x] `deploy:log` — stored per-step deployment output with step filtering
  and line limits, typed Gateway API forwarding. Pest:
  `tests/Feature/Commands/Deploy/DeployCommandTest.php`.

## E2E

- [x] Docker feature E2E:
  `composer test:e2e:docker -- --filter='manages and runs a production app deployment pipeline'`
  (`tests/E2E/DeployCommandTest.php`).

## Activity backfill

- [x] Tech-contract sections exist for all deploy commands. Runtime activity
  logging follows the gateway API/command logging backfill path; deploy emits
  command/API envelopes without storing captured command output in activity
  metadata.
