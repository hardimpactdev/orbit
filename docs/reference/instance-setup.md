---
title: "Instance setup and teardown"
description: "How a Project stores named setup and teardown commands, and when Orbit runs them for development Instances."
covers:
  - apps/gateway/app/Domain/{Projects/{LifecyclePhase,LifecycleStep,ProjectLifecycleStepStore,GatewaySetupSteps}.php,Instances/{StepLists/**,InstanceSourceProfileGuard.php}}
  - apps/gateway/app/Actions/*/{StartDevelopmentInstanceCreateAction,RegisterInstanceAction,StartInstanceStepListAction}.php
  - apps/gateway/app/Infrastructure/{Instances/RemoteDevelopmentInstanceConfigurator,AppDev/DevelopmentSshExecutor}.php
  - apps/gateway/app/Http/Controllers/Api/ProjectLifecycleStepsController.php
  - apps/gateway/{app/Models/ProjectLifecycleStep.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/Commands/Instances/{SetupInstanceCommand,*SetupStepCommand,*TeardownStepCommand}.php
  - swamp/workflows/workflow-operation-instance-{setup-direct,create}.yaml
---

# Instance setup and teardown

A Project stores two ordered lists of named shell commands for host-checkout development Instances: setup steps and teardown steps. New development Instances run in [Instance containers](/reference/development-containers), which use the Project's [development deploy steps](/reference/deployments#development-deploy-steps) instead (`instance:setup` refuses container Instances with `operation.kind_unavailable`). Production Instances use [production deploy steps](/reference/deployments#deploy-steps).

Each step is stored on the Project with a name, command, timeout, and position (never written as a script into the checkout). `instance:setup` records a digest of the list at admission; if the list changes before execution starts, the Operation is refused and you must start a new one.

## Record a step

Manage setup and teardown steps on the Project using its numeric `--project` ID:

```bash
orbit instance:setup-step:create install-php --project=4 --command='composer install --no-interaction'
orbit instance:setup-step:create install-js --project=4 --command='vp install' --after=install-php
orbit instance:teardown-step:create drop-sqlite --project=4 --command='rm -f database/database.sqlite'
```

| Command | Behavior |
| --- | --- |
| `instance:setup-step:create NAME --project=ID --command=COMMAND` | Adds a setup step (`--timeout`, `--before`, `--after`, `--rebalance`, `--json`). |
| `instance:setup-step:list --project=ID` | Lists setup steps in order (`--json`). |
| `instance:setup-step:update NAME --project=ID` | Updates `--command`, `--timeout`, placement, or `--rebalance` (`--json`). |
| `instance:setup-step:destroy NAME --project=ID` | Deletes a setup step (`--yes` required noninteractively, `--json`). |
| `instance:teardown-step:*` | Same four commands for teardown steps. |

Orbit validates each step before saving:

| Field | Rule |
| --- | --- |
| `name` | 1 to 63 lowercase letters, digits, or hyphens, starting and ending with a letter or digit. Unique in its list. |
| `command` | Non-empty UTF-8 string, at most 16 KiB, no NUL bytes. |
| `timeout_seconds` | Integer from 1 to 540 (default `240`). |
| `before`, `after` | Name of another step in the same list (mutually exclusive). |
| `rebalance` | Other steps and their new timeouts updated atomically in the same write. |

Each list allows at most 32 steps, and step timeouts sum to at most 540 seconds (legacy lists above 540 seconds cannot raise their total). [Activity](/cli/activity) never records step command text or output.

### Make room in a full list

Pass repeated `--rebalance=NAME=SECONDS` flags to adjust other steps' timeouts in the same write while keeping the list total within 540 seconds:

```bash
orbit instance:setup-step:create browsers --project=4 --command='npx playwright install' --timeout=180 \
  --after=install-js --rebalance=build-assets=360
orbit instance:setup-step:update build-assets --project=4 --timeout=540 --rebalance=install-js=360
```

## Credentialed Git

For private repositories, Orbit mints a GitHub App token with a short lifetime and `Contents: read` access (never stored in checkout config or command arguments; `gh_cli` is refused on `instance:create`). Fetches run as the Node's managed user over HTTPS only (`GIT_ALLOW_PROTOCOL=https`, `http.followRedirects=false`) with checkout hooks, filesystem monitors, and credential helpers disabled so an untrusted `.git/config` cannot hijack credentials.

A seeded worktree create returns `instance.branch_in_use` if the requested branch is already checked out in another worktree.

## Run setup

Run setup manually on an active host-checkout development Instance with `instance:setup` (`POST /api/v1/instances/{instance}/setup`, returning HTTP 202 with an Operation):

```bash
orbit instance:setup <instance> [--idempotency-key=KEY] [--json]
```

Setup also runs during host-checkout `instance:create` retries and `instance:register --setup` (never during `instance:clone`). Commands run in order via `bash -eu` from the repository root as the Node's managed user (exporting `VP_HOME` when a Vite+ store is configured), stopping at the first failure. Stdout is discarded; on failure, Orbit keeps up to 4 KiB of redacted stderr in `error.detail`.

Setup steps receive these environment variables:

| Variable | Value |
| --- | --- |
| `ORBIT_INSTANCE_ID` | Numeric ID of the Instance. |
| `ORBIT_INSTANCE_NAME` | Name of the Instance. |
| `ORBIT_SEED_PATH` | Checkout path of `default` on the same Node (empty if `default` has not deployed). |
| `ORBIT_SEED_COMMIT` | Last [deployed commit](/reference/deployments#development-defaults) of `default` (empty if undeployed). |

For routed Laravel checkouts, `.env` (with `APP_URL`, `APP_KEY`, and `APP_NAME`) is already written before setup runs. If a setup step fails during `instance:create`, Orbit retains the unfinished Instance and source so you can fix the step and retry `instance:create` or discard it with [`instance:destroy`](/reference/instance-removal). Container Instances configure `Orbit Agent <agent@orbit.local>` as the default Git identity when unset, and check required Processes at clone registration (`instance.container_process_inactive`).

## Deadlines

Each setup or teardown step uses its stored timeout (summed up to 540 seconds per list):

- **Explicit `instance:setup`:** Bounded by the 540-second list budget.
- **Container `instance:create`:** 1,800 seconds plus development deploy step timeouts, plus 180 seconds for a default's server database and 630 seconds for a Laravel default's [PHP symbol index](/reference/development-containers#php-symbol-index).
- **Host checkout `instance:create` retry:** 60 seconds plus up to 540 seconds for setup, 30 seconds for Processes, 240 seconds for a Route, and 180 seconds for databases.
- **Destroy (`instance:destroy`):** 60 seconds (host) or 600 seconds (container), plus up to 540 seconds for teardown, 330 seconds per withdrawn Route, and 180 seconds for databases.

A step that exceeds its timeout or runs out of list budget ends the Operation with `command.deadline_exceeded` (`outcome: deadline`).

## Run teardown

During `instance:destroy` of an active host-checkout development Instance, Orbit runs the Project's teardown steps after source checks pass and before deleting the checkout. Teardown may remove ignored files, but modifying tracked files blocks normal removal (retry with `--force`). Any failing or missing teardown step stops removal after Route withdrawal and returns `instance.teardown_step_failed` or `instance.teardown_step_unavailable` with up to 4 KiB of redacted stderr.

## Gateway-owned setup steps

A setup step whose command is `@orbit/ci-caches` invokes a cache importer owned by the Gateway rather than an arbitrary shell command (refused in teardown). Check logs cap displayed commands at 4,096 characters.

## Import CI main caches

Add `@orbit/ci-caches` after your dependency install steps in a monorepo whose CI publishes Pint and PHPStan caches to `refs/orbit/ci-cache/<sha>` (see [CI cache refs](/reference/implementation-loop#ci-cache-refs)):

```bash
orbit instance:setup-step:create ci-caches --project=PROJECT --command='@orbit/ci-caches' --timeout=120 --json
```

Orbit resolves the exact tested baseline SHA (`HEAD` on `main`, or the recorded `refs/worktree/orbit/ci-baseline` ancestor on a task branch) and fetches `refs/orbit/ci-cache/<selected-sha>` via the Gateway's GitHub App (`Contents: read`). It never falls back to another SHA.

`bin/ci-cache-ref import` validates manifest schema, file checksums, lockfiles, tool configs, exact PHP version (Pint `php` version and PHPStan result-cache PHP version), and safety bounds (regular files only, no symlinks or traversal, at most 1,024 files and 64 MiB expanded) before writing missing caches into `vendor/`. Existing caches are preserved.

| Situation | Result |
| --- | --- |
| Code fetch or auth fails | Source operation fails. |
| Cache ref missing, pruned, or unreadable | Setup continues cold and logs the reason. |
| Ref SHA or manifest mismatch, or payload exceeds limits | Payload rejected; setup continues cold. |
| Lockfile, tool config, or PHP version differs | That tool cache is skipped and runs cold. |
| Importer binary missing or write fails | Fails the named setup step. |

Because PHPStan executes its result cache as PHP code, only trust cache refs published by your repository's own protected `main` CI workflow.

## Failure codes

Orbit returns these codes for setup and teardown failures:

| Code | HTTP / Scope | Cause |
| --- | --- | --- |
| `setup_step.not_found`, `teardown_step.not_found` | 404 | Named step does not exist in the Project's list. |
| `instance.step_not_admitted` | 409 | Step list changed between admission and execution. |
| `instance.setup_unavailable` | 409 | Target is not an active host-checkout development Instance. |
| `operation.kind_unavailable` | 503 | `instance:setup` targeted a container Instance. |
| `instance.setup_step_failed` | Operation | A setup command exited non-zero. |
| `instance.teardown_step_failed` | Operation | A teardown command exited non-zero or timed out. |
| `instance.setup_step_unavailable` | Operation | A setup command was missing or not executable (`outcome: missing`). |
| `instance.teardown_step_unavailable` | Operation | A teardown command was missing or not executable (`outcome: missing`). |
| `command.deadline_exceeded` | Operation | A step exceeded its timeout or the list budget. |

## Why it works this way

Storing each step as a named row on the Project lets failures report the exact failing step, keeps all Instances of a repository consistent, and lets operators reorder steps without editing scripts inside checkouts. Registration skips setup by default because adopted checkouts are usually already prepared (`--setup` opts in). Failed creates keep the unfinished Instance rather than auto-deleting it so you can inspect errors and retry without losing context, while teardown failures halt removal so operator cleanup finishes before files are deleted. Using a reserved `@orbit/ci-caches` token lets the Gateway deliver validated CI caches without handing GitHub credentials to workspaces.
