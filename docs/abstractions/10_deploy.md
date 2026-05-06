# Deploy Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing deploy
command ports.

Product behavior remains owned by `docs/commands/10_deploy/**` and the
top-level product docs.

## Domain Constraints

- Deploy is a command domain, not a state family.
- Deployment policy, run history, run logs, and latest deployment status are
  app-owned gateway state.
- Deploy commands apply only to production apps.
- Deployment reads use gateway durable state and do not inspect live node
  state.
- Deployment runs execute configured steps on the app owning node through the
  gateway-owned execution edge.
- Deployment steps are arbitrary shell commands. Orbit records the step exit
  code and captured output; lifecycle semantics belong to any command invoked
  by the step.
- Deployment health belongs to `doctor --family=app`; there is no deploy
  doctor family.

## Schema And Model Pattern

- `deploy_steps` store ordered app-owned step definitions: title, command,
  timeout, and optional retention metadata.
- `deployment_runs` store durable app-owned run records and latest deployment
  status.
- `deployment_run_steps` store one captured step execution with stdout,
  stderr, exit code, status, and timestamps.

Keep deploy records gateway-owned. Node-side execution is an enactment edge,
not a second source of truth.

## Command Pattern

- `deploy-step:add`, `deploy-step:list`, and `deploy-step:remove` manage
  gateway deployment policy for one production app.
- `deploy:run` creates durable run history before executing the first step.
  Streaming human output should use the shared SSE progress primitives.
- `deploy:history` and `deploy:log` read stored gateway history only.
- Control and app callers use typed gateway API requests. Gateway callers use
  local gateway state plus the gateway-owned `RemoteShell` edge.
- Retention behavior is step metadata and app-owned deployment cleanup, not a
  standalone state family.

## E2E Pattern

- Use Docker feature E2E for gateway policy, run history, and controlled
  command execution.
- Use Incus VM-feature only if an assertion needs real host init or
  filesystem/service behavior that Docker cannot model.

## Evidence Pointers

- `docs/commands/10_deploy/README.md`
- `docs/commands/10_deploy/deploy-concepts.md`
- `docs/commands/10_deploy/1_deploy-step-add`
- `docs/commands/10_deploy/2_deploy-step-list`
- `docs/commands/10_deploy/3_deploy-step-remove`
- `docs/commands/10_deploy/4_deploy-run`
- `docs/commands/10_deploy/5_deploy-history`
- `docs/commands/10_deploy/6_deploy-log`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Console/Commands/DeployCommand.php`
- Old evidence: `../orbit-old-may/app/Services/DeploymentRunner.php`
- Old evidence: `../orbit-old-may/app/Services/DeploymentHistoryPruner.php`
- Old evidence: `../orbit-old-may/app/Http/Controllers/Api/DeployRunController.php`
- Old evidence: `../orbit-old-may/tests/Feature/DeployCommandTest.php`
- Old evidence: `../orbit-old-may/tests/Unit/Services/DeploymentRunnerTest.php`
