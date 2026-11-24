---
title: "Instance releases"
description: "How production Instances build releases and select current code, and how development defaults deploy in their checkout."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{StartInstanceDeployAction,StartInstanceRollbackAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/ProductionApplicationPaths.php
  - apps/agent/src/commands/{production,production_home,production_home_tests,deploy_step,dev_deploy,dev_deploy_tests}.rs
  - apps/gateway/app/Http/{Controllers/Api/{InstanceDeploymentsController,InstanceDeployStepsController,InstanceReleasesController,InstanceRollbacksController,ProjectDevelopmentDeployStepsController},Requests/Projects/*ProjectDevelopmentDeployStepRequest}.php
  - apps/gateway/{app/{Models/{InstanceDeployment,InstanceDeployStep,ProjectDevelopmentDeployStep},Data/Instances/InstanceDeploymentData,Console/Commands/PruneInstanceDeployCopiesCommand}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_05_000000_add_caller_node_id_to_instance_deployments}.php}
  - swamp/extensions/behaviors/{automatic-deploy/**,operations/development-deploy/**}
  - packages/php-sdk/src/{Requests/Projects/*DevelopmentDeployStep*,Responses/{Projects/*DevelopmentDeployStep*,Deployments/InstanceDeploymentResponse}}.php
---

# Instance releases

Orbit deploys two kinds of Instances: production Instances on `app-prod` Nodes and `default` development Instances on `app-dev` Nodes.

A production deployment builds the new release in the Instance's off-traffic preparation container, promotes a verified clone to serve traffic, and keeps the previous live release stopped for fast rollback; see [Production containers](/reference/production-containers). A development default deployment updates the Project's default branch in place and takes a fresh seed so new clones and task workspaces start at the latest commit; see [Instance containers](/reference/development-containers).

```bash
orbit instance:deploy 42
orbit instance:release:list 42
orbit instance:rollback 42
```

## Deploy

A deployment reads the Instance's branch, deploy steps, environment, and runtime definitions once at admission, then runs them as a single [Operation](/reference/operations). Orbit runs no migrations, cache clears, dependency installs, asset builds, or custom health checks unless you configure them as deploy steps.

### Production releases

Every `app-prod` deployment uses the container release lifecycle described in [Production containers](/reference/production-containers):

1. **Prepare and build.** Orbit wakes the preparation container, checks out the commit, links [shared paths](#shared-paths), renders `.env`, prepares [Vite+](#vite-store-prerequisite), and runs `before_activation` steps.
2. **Clone and verify.** Orbit snapshots the finished build, boots a `hot` clone, and verifies readiness on its health path (`/up` for Laravel, `/` otherwise).
3. **Switch and hand over.** Orbit switches Caddy traffic to the new container and moves timers and active `restart_on_deploy` Processes over, leaving stopped Processes stopped.
4. **After activation and retire.** Orbit runs `after_activation` steps in the new live container, drains and stops the old live container as `old`, and records release states.

For Laravel apps with `storage` shared, Orbit links `public/storage` automatically without a `storage:link` step, and maps supported [host paths](/reference/production-containers#host-paths) in `.env`. To keep a Process from restarting on deploy, rollback, or production `env:sync`, pass `--no-restart-on-deploy` to [`process:create` or `process:update`](/cli/process) (or set `restart_on_deploy: false` in the API). For Laravel Horizon managed as an Instance Process, keep the default and remove any hand-made `php artisan horizon:terminate` after-activation step.

### Timeout budgets

Each deploy step enforces its own timeout (`timeout_seconds`). A required step that times out stops its process group and skips all remaining steps. The overall Operation also has a run budget:

- **Production release:** `before_activation` allows 2,400s (3,450s with Vite+) plus `timeout_seconds + 20` per step; `after_activation` allows 60s plus `timeout_seconds + 20` per step, plus 7,800s for handover.
- **Development default:** 900 seconds plus step timeouts, and these additions:
  - 5,590 seconds for a nested-fleet default.
  - 1,800 seconds for the proof Project's [seed-hold wait](/reference/development-containers#the-seed-hold).
  - 630 seconds for a Laravel default's [PHP symbol index](/reference/development-containers#php-symbol-index).
- **Rollback:** 10,260 seconds for a production container rollback, or 900 seconds for a legacy host rollback.

Each production phase is capped at 90,000 seconds (`deployment.step_budget_exceeded`, 422). When a run exceeds its budget, Orbit records `operation.timed_out`. When a run finishes without a readable end check, Orbit records `operation.result_unreadable`.

## Development defaults

A `default` Instance on an `app-dev` Node tracks the Project's default branch (production branch overrides do not apply). New development defaults run in [Instance containers](/reference/development-containers); existing host checkouts continue working until migrated. You can deploy a default manually with `orbit instance:deploy ID`, `POST /api/v1/instances/{instance}/deploy`, or MCP `instance-deploy`, or let the [automatic deployment policy](#automatic-development-defaults) keep it current.

A development deployment runs in two phases:

1. **Source preparation.** Orbit fetches the default branch with [credentialed Git](/reference/instance-setup#credentialed-git) and checks out the target commit.
2. **Deploy steps.** The Project's [development deploy steps](#development-deploy-steps) run in list order (reported under `before_activation`). There is no activation switch because the workspace serves directly.

On container defaults, source preparation removes untracked non-ignored files (`workspace_cleaned`; [The default keeps a clean tree](/reference/development-containers#the-default-keeps-a-clean-tree)) and records the [seed manifest](/reference/development-containers#the-seed-manifest) before taking the seed.

Source preparation refuses dirty tracked files (`deployment.checkout_dirty`), a local branch with commits missing from the fetched target (`deployment.branch_diverged`), and checkouts that would overwrite an untracked file. Both checkout and steps acquire a `flock` on the checkout directory shared with [setup and teardown steps](/reference/instance-setup); a conflicting lifecycle step fails the deploy with `instance.lifecycle_busy`. Steps must not modify tracked files, or the next deploy will see a dirty checkout.

On container defaults, the first step (`Start the container`) runs a `probe` right after starting the container to detect when the workspace `HEAD` is already past the target commit on the default branch's first-parent chain (applying `deployment.commit_not_on_branch`, `deployment.checkout_dirty`, and `deployment.branch_diverged` first).

For a release or automatic origin, an older target ends immediately as a succeeded no-op (`result.outcome: already_newer`, `target_commit`, `kept_commit`) without taking a seed, restarting units, or running later phases. A release publishes the seed only if it matches `kept_commit` ([Refresh after a release](/reference/development-containers#refresh-after-a-release)). Recorded source facts remain at the admitted target commit until the next real deploy.

A manual deploy (or a legacy admission without `origin`) refuses an older commit with `deployment.commit_behind_checkout` and changes nothing. Once a release-admitted deploy ends, it never holds the release's role converge on its Node ([Gateway recovery](/reference/gateway-recovery#fleet-rollout)). An unknown executor result still holds the Instance lock and ends the release refresh with `instance.refresh_fenced`.

When every required step passes, Orbit updates the default's `seed_commit` (and takes a new container seed, restarting its running development Processes on the new commit). When a required step fails, later steps do not run, the checkout stays at the new commit without rolling back, and `seed_commit` stays at the last successful commit so the automatic policy retries after backoff.

Additional behavior for container defaults:

- **Sleep and wake:** Deploying a [sleeping default](/reference/development-containers#sleep-and-wake) resumes or boots it and clears the asleep marker. Manual deploys count as activity for idle sleep; automatic deploys do not.
- **Pending Routes and migrations:** A pending default Route projection refuses deploy admission with `deployment.projection_pending` (409). An unfinished [`instance:migrate`](/cli/instance/migrate) refuses deployment with `instance.lifecycle_busy`.
- **Removed Processes:** Development Processes removed from the Project are cleaned up during deploy and listed in `processes_removed`.
- **Nested-fleet defaults:** Verify [fleet rules](/reference/development-containers#fleet-rules) before the first seed (`instance.container_fleet_drift`), receive `nested_fleet_club_key`, and refresh the inner fleet.
- **Proof Project defaults:** A passed deploy starts asynchronous [CI seed copies](/reference/development-containers#the-seed-on-each-ci-node).

### Container source facts

Before admitting a manual or automatic deployment of a container default, Orbit refreshes the Project's source facts (PHP version, source classification, and environment template) for the requested commit or current default-branch head, and pins the deployment to that SHA. Orbit first checks that any [container migration](/reference/development-containers#migrate-a-checkout-instance) has settled; if the source configuration changes during the read, admission refuses with `deployment.source_changed` (409).

If the source read fails (`project.source_unavailable`, 502), metadata is invalid or unsupported (422, such as `app-dev.php_version_unsupported` or `project.source_defaults_incomplete`), or the required static PHP build is missing (`instance.host_unavailable`, 409), Orbit refuses the deploy and leaves stored facts unchanged. Orbit never deploys a container default using stale source facts.

A successful admission updates Project-wide facts and environment templates immediately (following the [template merge rules](/domains/applications#project-environment-template)) and does not revert them if the deploy later fails or is cancelled. The seed itself updates only after the deploy succeeds. Checkout Instances and production deployments do not refresh Project source facts during deploy.

### Seeds

A new development Instance on the same Node starts from the default's `seed_path` and `seed_commit`, creating a linked worktree at that commit. [Setup steps](/reference/instance-setup#run-setup) receive `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` to copy dependency folders for a faster start, then still run locked installs because a concurrent deploy may update the checkout while they copy. A default Instance's own setup receives no seed.

## Deploy steps

Deploy steps are named shell commands that always run from the repository root, not from a nested application directory. For a web root like `apps/site/public`, write `cd apps/site && php artisan migrate --force`. Storing or editing a step never starts a deployment, and Activity entries and errors never expose step commands.

### Production deploy steps

Each production Instance stores its own ordered deploy steps, grouped into `before_activation` and `after_activation` phases.

| Field | Contract |
| --- | --- |
| `name` | Unique within the Instance. 1 to 63 lowercase letters, digits, or hyphens, starting and ending with a letter or digit. |
| `phase` | `before_activation` (default) or `after_activation`. |
| `command` | Non-empty UTF-8 string, at most 16 KiB, without NUL bytes. |
| `timeout_seconds` | Integer from 1 to 900. Default `300`. |
| `required` | JSON boolean (`--required=true` or `--required=false`). Default `true`. |

A new step appends to its phase unless `before` or `after` (mutually exclusive) names another step in the same phase. An Instance allows at most 32 steps with a combined timeout of at most 3,600 seconds. Steps run with `bash -eu`, empty stdin, a cleared environment containing the rendered Instance `.env`, and suppressed output so secrets never enter run history. A failed best-effort step (`required: false`) is recorded in `best_effort_steps` and allows the deployment to continue.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | Lists steps in phase and placement order. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step (`name` and `command` required; optional `phase`, `timeout_seconds`, `required`, `before`, or `after`). |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{name}` | Updates `command`, `phase`, `timeout_seconds`, `required`, or placement. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{name}` | Removes the step while keeping the order of the rest. |

The deployment branch is stored on the Instance (`PATCH /api/v1/instances/{instance}` with `branch`) and does not change when the Project's default branch changes. Step and branch changes require an access grant to the Instance's Node and refuse non-production Instances with `deployment_config.unavailable` (409).

### Development deploy steps

A Project stores one shared list of development deploy steps, independent of setup and teardown. Manage them with `project:dev-deploy-step:list`, `create`, `update`, and `destroy` (`--project=ID`), via `/api/v1/projects/{project}/dev-deploy-steps` (`GET`, `POST`, and `PATCH`/`DELETE` on `/{name}`), or through MCP.

Development steps use the same `name`, `command`, `timeout_seconds` (1 to 900, default 300), `required` (default `true`), `before`/`after` placement, and list limits (max 32 steps, max 3,600 seconds total) as production steps. Step names cannot be renamed, and omitted fields on `PATCH` stay unchanged.

A development step may also declare `inputs`: up to 16 unique repository-relative file or folder paths (such as `composer.json` or `apps/web/bun.lock`), each 1 to 255 bytes, without a leading `/`, `..` segment, control character, line separator, or glob character (`*`, `?`, `[`). On `PATCH`, a list replaces `inputs`, and `null` or `[]` clears them.

Development commands run with a clean environment (`HOME`, `USER`, `PATH`, `ORBIT_SEED_PATH`, `ORBIT_SEED_COMMIT`, plus `VP_HOME` under Vite+) and do not load `.env` into the shell; the application reads `.env` itself. Command output is suppressed. For best-effort steps (`required: false`) in a container deploy, Orbit records `{name, exit_code}` in `best_effort_steps` (0 to 255, with `124` for timeout; skipped steps have no row). [`instance:deploy`](/cli/instance/deploy), [`instance:deployment:show`](/cli/instance/deployment-show), and the web UI warn about any non-zero exit code from a best-effort step while still passing the deployment.

#### Step inputs

In a container deployment, Orbit skips a development step with `inputs` when the Git object ID of every input at the deployed commit and the step's command match the step's last successful run in that workspace. Any changed input or command, missing input, or absent record runs the step. Steps without `inputs` always run, and host checkout deployments always run every step.

Orbit stores the last-run record inside the workspace at `.git/orbit-deploy-steps`, so clones inherit it along with built files. Delete `.git/orbit-deploy-steps` to force every step to run on the next deploy. Avoid setting `inputs` on a step whose command generates files from sources outside those inputs (such as a Composer `post-install-cmd` that compiles documentation or API schemas).

### Vite store prerequisite

When a Project declares `vite-plus` and a deploy step runs JavaScript tooling (`npm-build`, or commands using `vp`, Node, npm/npx, pnpm, Yarn, Bun, Vite, or Corepack), Orbit prepares the [Project's Vite+ environment](/reference/tools#project-environments-in-the-vite-store) in the Instance's private cache before the step runs. It verifies pinned runtime versions without running package install scripts as the store owner.

The default JavaScript step runs `vp install --frozen-lockfile` and `vp run build` via `vp env exec` with the recorded Node and package-manager versions; customized commands keep their own flags and scripts. If a runtime cannot be installed, the deploy fails before the build step with `deployment.vp_store_failed`. Invalid or incompatible requirements fail with `deployment.vp_requirements_invalid`.

## Shared paths

Production Instances keep persistent directories across releases (mounted at `/srv/shared` in production containers, or stored under `<home>/<path>` on legacy host homes). Orbit links each shared path from the new release before `before_activation` steps run; deploy steps must preserve these links.

Laravel app Projects default to `storage` relative to the application directory (for example, `apps/site/storage` for root `apps/site/public`). Other Project types default to no shared paths.

```bash
orbit instance:update 42 --shared-paths='["storage","var/uploads"]'
orbit instance:update 42 --shared-paths='[]'
orbit instance:update 42 --shared-paths=null
```

You can also set `shared_paths` via `PATCH /api/v1/instances/{instance}` (`[]` disables sharing; `null` restores the Project-type default). Changes require Node access and the Instance operation lock, apply on the next deployment, and do not move files in the currently serving release.

Shared path validation rules:

- Paths must be relative to the repository root, at most 32 distinct non-overlapping paths, each at most 255 bytes.
- Path components may use letters, digits, dots, underscores, and hyphens.
- Absolute paths, `.` and `..`, hidden top-level paths, and Orbit's reserved names (`releases`, `current`, `env`, `database.sqlite`) are refused.
- Source and destination ancestors cannot be symlinks, and a shared path cannot replace a web root or its ancestors.

### Public storage

When `storage` is a shared path and the web root is `public`, Orbit automatically creates `public/storage -> ../storage/app/public` if absent. Do not add an Artisan `storage:link` step. On production containers, readiness refuses a candidate with `deployment.not_ready` if `public/storage` is broken or removed by a build step. On legacy host homes, invalid web-root symlinks are refused during preparation and activation, and Caddy receives read access only to `storage/app/public` with default ACLs for new uploads.

### Replace a hand-made link-storage step

If an Instance already keeps its data in `<home>/storage` (or the matching nested path), confirm `storage` is in `shared_paths` and delete the custom step with `DELETE /api/v1/instances/{instance}/deploy-steps/link-storage` before the next deploy. If runtime files still live only inside the current release, copy them into the persistent directory first (using a maintenance window if writes can occur), because automatic seeding copies from the new release's source tree rather than the running release.

## Deploy through the API

Deployments and rollbacks run asynchronously as [Operations](/reference/operations).

| Request | Body | Result |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | `{}` or `{"commit":"SHA"}` | `202` with an `instance.deploy` Operation. |
| `POST /api/v1/instances/{instance}/deploy/automatic` | `{"commit":"SHA"}` + `Idempotency-Key` | `202` with an automatic `instance.deploy` Operation (requires Gateway Node access). |
| `POST /api/v1/instances/{instance}/rollback` | `{"release": "<name>"}` | `202` with an `instance.rollback` Operation. |
| `GET /api/v1/instances/{instance}/releases` | none | Returns `releases` and `selected_release` (null on development defaults or before first deploy). |

When an Operation finishes, `result` includes `deployment_id`, `release`, `selected_release`, and `commit` (plus `best_effort_steps` when optional steps ran, and `warnings` on legacy cleanup warnings). A failed Operation names the step in `failed_step` (such as `before_activation.<step>` or `after_activation.<step>`) and the code in `error_code`.

Admission refuses non-deployable Instances with `deployment_config.unavailable` (409), busy Instances with `operation.target_busy` (409), and production Instances lacking stored environment configuration with `env.configuration_missing` (409).

### Pinned commits and automatic callers

`orbit instance:deploy --commit=SHA` sends `{"commit":"SHA"}` (exactly 40 or 64 lowercase hex characters) to the normal deploy endpoint. Orbit validates the Project's recorded repository identity at admission and verifies that the commit lies on the deployment branch's first-parent chain before running steps (`deployment.commit_not_on_branch` otherwise). It never substitutes a newer branch head.

`POST /api/v1/instances/{instance}/deploy/automatic` (MCP `instance-deploy-automatic`) starts the same `instance.deploy` Operation with automatic origin. It has no CLI command and requires Gateway Node access, an eligible Instance, a `commit`, and an `Idempotency-Key`. Both deploy endpoints reject unknown body fields (`triggered_by`, `automatic`, `caller_node_id`).

An automatic idempotency fingerprint binds caller, command, Instance, and commit: replaying the exact request returns the original Operation even after completion, while reusing the key with different inputs returns `operation.idempotency_conflict`. Under the Instance lock, automatic admission rechecks enablement, manual pause (`deployment.paused`), and rollback blocks (`deployment.commit_blocked`).

### Execution copies

Private material for a deploy or production container rollback (credentials, step commands, and rendered `.env`) is written to Operation-scoped vault keys (`production-release-operation-<id>` for production containers; `development-deploy-operation-<id>-*` for development) and deleted automatically when the Operation reaches a terminal state. A timed-out run keeps its copies until it settles or passes the 60-minute hold limit.

Run `php artisan orbit:instance-deploy-copies-prune` (with optional `--dry-run`) on the Gateway to remove any leftover copies from ended deploys or container rollbacks, such as after a transient delete failure.

## Roll back

Rollback is production-only; development Instances refuse with `deployment_config.unavailable` (409). A rollback switches traffic back to a retained release, refreshes the serving PHP runtime, and restarts active opted-in Processes. It fetches no code, writes no environment changes (other than applying current `.env` to the restarted container), runs no deploy steps, and never alters databases or shared data.

- **Production containers:** `orbit instance:rollback <instance>` (with optional `old` SHA) restarts `old`, checks readiness, switches traffic, and stops the active live release as `old`.
- **Legacy host homes:** Selects one of the up to three retained releases by updating `current` atomically, reloading PHP-FPM, and restarting opted-in Processes.

Before the first container release confirms, rollback refuses with `deployment.selection_unconfirmed` (409). To roll back further than `old`, deploy an older revision with `orbit instance:deploy --commit=<sha>`.

| Code | HTTP | Cause |
| --- | --- | --- |
| `rollback.release_not_retained` | 422 | The requested release is not retained (list available releases with [`instance:release:list`](/cli/instance/release-list)). |
| `deployment.selection_unconfirmed` | 409 | The first production container release has not yet confirmed. |
| `deployment_config.unavailable` | 409 | The Instance is not a production Instance. |
| `operation.target_busy` | 409 | Another operation holds the Instance. |

## Failures

The failed boundary determines which release serves afterward. Orbit never undoes the side effects of a deploy step, environment sync, or database migration.

| Failed boundary | Selected release |
| --- | --- |
| Preparation, capacity check, source, links, environment sync, `before_activation` step, or container readiness | The earlier live release (or none before the first deploy). |
| Activation / traffic switch | The new release if the switch succeeded, otherwise the earlier release. |
| Handover, PHP refresh, Process restart, `after_activation` step, or retire/cleanup | The new release. |

Common deployment error codes and recovery actions:

| Error code | Cause | Recovery |
| --- | --- | --- |
| `deployment.repository_rebind_required` | The production home records a different URL for the same repository (for example, SSH after moving to HTTPS). | Run `orbit project:update <project> --repository=<url>` to [re-bind the home](/reference/projects#repository-changes). |
| `deployment.repository_mismatch` | The recorded repository identity does not match the Project origin. | None in place; the target belongs to another repository. |
| `deployment.identity_invalid` | Active `app-prod` placement, account, or repository identity does not match. | Verify Node role, placement, and Project repository settings. |
| `deployment.route_unavailable` | The production Instance lacks an active application Route for readiness and traffic switching. | Attach or activate an application Route, then deploy again. |
| `deployment.selection_invalid` | The live link or read-back does not match the recorded release. | Inspect the live release state on the Node and reconcile. |
| `deployment.selection_unconfirmed` | The first container release has not confirmed on the Gateway. | Follow recovery in [Production containers](/reference/production-containers#a-checkout-production-instance) and re-run `instance:deploy`. |
| `deployment.migration_commit_mismatch` | A pinned commit during initial container migration differs from the serving host checkout commit. | Deploy the serving checkout commit first to complete migration, then deploy the new commit. |
| `deployment.migration_source_unconfirmed` | The legacy host checkout has no confirmed 40-character serving commit. | Deploy or confirm the serving checkout commit before migrating. |
| `deployment.source_unconfirmed` | The resolved production branch head is not a confirmed 40-character Git SHA. | Check repository access and branch state, then retry. |
| `deployment.step_budget_exceeded` | Configured deploy steps exceed the 90,000-second phase deadline. | Reduce step timeouts or split work, then retry. |
| `deployment.projection_pending` | The development default has an unfinished Route projection. | Wait for or run the Route update, then deploy again. |
| `instance.deploy_lock_timeout` | A container default step waited 60 seconds for a busy lock. | Run the deployment again. |

See [Production containers errors](/reference/production-containers#errors) for container-specific runtime errors (`deployment.host_path_unsupported`, `deployment.node_capacity`, `deployment.not_ready`, `deployment.switch_failed`).

## Retained releases

Release retention depends on whether the Instance runs in production containers or a legacy host home:

- **Production containers:** Orbit retains at most 2 releases (`live` and `old`), listed by [`instance:release:list`](/cli/instance/release-list) as 12-character commit SHAs.
- **Legacy host homes:** A successful deploy retains at most 3 releases (`current`, the previous selection, and the newest other release). Rollback and failed deploys delete nothing.

On legacy host homes, cleanup never deletes a release still referenced by a running process, and deleting a release removes only its shared-path symlinks without touching persistent data. [Instance removal](/reference/instance-removal) deletes the production account, releases, environment files, and SQLite data; back up needed data first.

## Automatic deployments

Orbit polls branch heads through the Gateway using `git ls-remote` and the Project's [read credential](/reference/instance-setup#credentialed-git), with no public GitHub webhook required. The Gateway caches GitHub App installations for up to 60 minutes and encrypted read tokens for up to 50 minutes (dropping the cache on a failed read).

When the Instance already runs the target commit, the policy does nothing. Otherwise it admits a pinned `instance.deploy` Operation, coalescing intermediate pushes while a deploy runs and skipping busy Instances (`busy`). A branch head that cannot be read fails the tick for that Instance with `source.head_unavailable` while other Instances continue.

### Automatic development defaults

Every new commit on a Project's default branch deploys its container default once so the seed stays current. This is enabled by default and controlled by `default_auto_deploy`:

```bash
orbit project:update <project> --default-auto-deploy=false
```

A default deploys automatically only when it is an active `default` container Instance on an active `app-dev` placement, `default_auto_deploy` is on, and the Project has no [nested fleet](/reference/development-containers#nested-fleet). Nested-fleet defaults update through [refresh after a release](/reference/development-containers#refresh-after-a-release) instead (which takes `base` and runs any due disk shrink asynchronously in `orbit-fleet-shrink`).

Every minute, a Gateway precheck compares each eligible Project's default branch head with its default's `seed_commit`. Only defaults behind their branch enter the policy run:

- One deploy runs at a time per default (`deploying` for its own open deploy; `busy` for another Operation).
- Failed or interrupted deploys back off 10 minutes after the first failure and 20 minutes after the second.
- After three failures, the commit is marked `commit_failed` and skipped until a newer commit lands or a manual `instance:deploy` resets the count.
- Clones started during a deploy copy the old seed (or wait briefly during a seed retake).

### Production Instances

Enable automatic deployments on a production Instance after at least one manual deployment has succeeded:

```bash
orbit instance:auto-deploy:enable <instance>
```

The production policy runs every minute at second 30 (`30 * * * * *`) against the Instance's deployment branch. When the Project defines a `merge_check`, Orbit walks the branch's first-parent history for the newest commit that passed that GitHub Actions check run, descends from the currently deployed commit, and is not blocked. A pending check is rechecked each minute; a failed check is rechecked after at least 5 minutes.

Without a `merge_check`, every new branch head deploys (`instance:auto-deploy:enable` warns about this). Gating requires the [GitHub App](/reference/github-app); enabling automatic deploys on a Project with `merge_check` that uses GitHub CLI or a non-GitHub origin refuses with `instance.auto_deploy_gate_unreadable`.

| Result | Meaning |
| --- | --- |
| `up_to_date` | The Instance runs the branch head. |
| `started` | The policy started a deployment for the named commit. |
| `deploying` | The policy's deployment is still running. |
| `gate_pending` | The head's check is still running and no older commit is green. |
| `gate_failed` | The head's check failed with no older green commit (rechecked after 5 minutes). |
| `no_green_commit` | No eligible commit can deploy (head is blocked or does not descend from the deployed commit, such as after a force push). |
| `commit_failed` | The head is blocked with no `merge_check` gate (or on a development default). |
| `busy` | Another Operation holds the Instance. |
| `backing_off` | A failed commit is waiting for its retry backoff. |
| `paused` | A manual deployment or rollback paused automatic deployments. |
| `not_deployed` | No deployment has succeeded yet; run one manual deploy first. |
| `failed` | The tick could not read the head/check or start the deploy (`error_code` names why). |

#### Failures of automatic deployments

Failures before Project deploy steps run (`operation`, `preparation`, or `environment`) are transient: Orbit retries after 10 minutes, then after 20 minutes, and blocks the commit after three attempts. `deployment.node_capacity` retries on the same backoff schedule without counting toward the three-failure block.

Lasting failures block the commit immediately: a cancelled deploy, a commit not on the branch, a repository mismatch or rebind requirement, an undeployable Instance, or any failure in a deploy step or later. A blocked commit never deploys automatically again, while a newer commit deploys immediately. Failures before the newest manual deploy do not count.

#### Pause

Any manual deployment or rollback started after `auto_deploy` was last enabled pauses automatic deployments immediately (whether it succeeds, fails, or is still running). Automatic deployments stay paused across new commits until you run `orbit instance:auto-deploy:enable <instance>` again.

Resuming continues from the currently live commit, and any commit rolled away from remains permanently blocked until a newer commit lands on the branch. If a rollback or manual deploy completes between a tick's read and its deploy start, the lock recheck ends the automatic Operation with `deployment.paused` without counting as a commit failure.

`instance:show` displays the latest policy result, commit, head, and pause state. Notable transitions write an `auto_deploy.<result>` Activity entry, and [release alerts](/reference/gateway-recovery#release-alerts) (`instance:<id>`) notify once per commit on failure, once after a commit or pending head stalls for 30 minutes, and on pause.

### Swamp policy contracts

The policy worker reads GitHub evidence and publishes policy state exclusively through Gateway API endpoints requiring Gateway Node access (never directly from GitHub or the database):

| Request | Evidence or effect |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-source/head` | Fresh credentialed branch `head` (works without a GitHub gate). |
| `GET /api/v1/instances/{instance}/deployment-source/commits?base=<sha>` | Branch `head` and up to 20 first-parent candidates (from at most 100 reachable commits) with ancestry comparison (`reached_base`, `truncated`). |
| `GET /api/v1/instances/{instance}/deployment-source/checks?commit=<sha>` | Configured `gate` and latest check runs for the commit (requires GitHub App; returns `instance.auto_deploy_gate_unreadable` or `instance.auto_deploy_gate_unavailable` on error). |
| `GET /api/v1/instances/{instance}/deploy/automatic/receipt?request_key=<key>&commit=<sha>` | Exact keyed admission lookup bound to caller, command, Instance, and commit. |
| `PUT /api/v1/instances/{instance}/auto-deploy/policy` | Publishes public policy state guarded by `resumed_after`, `last_deployment_id`, and `history_version` (`instance.auto_deploy_observation_changed` on conflict). |

Instance responses expose `auto_deploy.policy_owner` (`swamp`), `resumed_after`, `blocked`, and `history_version`. Discovery uses `GET /api/v1/instances?auto_deploy=true`, which applies the same eligibility rules as admission (an inactive `app-prod` role refuses with `instance.auto_deploy_role_inactive`). Policy memory records are refreshed hourly (`automatic-deploy-memory-refresh`, `0 41 * * * *`) so retention never drops idle Instance state.

#### Clear an unresolved admission hold

If an automatic admission request loses its response, do not resend `POST .../deploy/automatic` to check its outcome. Instead, disable `auto_deploy` to pause new decisions, inspect the receipt, and resolve the key:

```bash
orbit instance:auto-deploy:disable <instance>
orbit instance:deploy:automatic:resolve <instance> <request_key> <commit> --caller-node=<original-node-id>
```

The API equivalent is `POST /api/v1/instances/{instance}/deploy/automatic/resolve` (MCP `instance-deploy-automatic-resolve`). Omit `--caller-node` to resolve your own Node's key.

If no admission exists for that key, resolution writes a retained cancelled Operation with `operation.admission_fenced` (zero attempts, no lock, no deployment) that permanently fences any delayed POST with that key. If an admission already exists, resolution returns its receipt unchanged so you can follow it to completion. For a terminal worker-lost receipt without effect evidence, inspect the live release and reconcile with a manual deploy or rollback before re-enabling `auto_deploy`.

## History

Orbit retains the last 50 deployment and rollback rows per Instance.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row including `events`. |

Each row records `kind` (`deploy` or `rollback`), `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by`, `caller_node_id`, `caller_node_name`, `best_effort_steps`, and `events`. Development deployments leave `release` and `selected_release` null. When a production `deploy` succeeds, Orbit queues a job to stamp [`deployed_at`](/reference/tasks#deploy-times) on Tasks included in that commit.

For container default deployments, `events` contains one `phase` entry per admitted development deploy step with its `step_name` and `status`:

| `status` | Meaning |
| --- | --- |
| `succeeded` | The step passed. |
| `failed` | The step failed (stops later steps if required; continues if best-effort). |
| `timed_out` | A best-effort step timed out (exit code 124). |
| `skipped_unchanged` | Skipped because its [step inputs](#step-inputs) and command did not change. |
| `interrupted` | The step started and the run ended before it finished. |
| `not_run` | An earlier failure or run end prevented the step from starting. |

Deployment origin is recorded in `triggered_by` alongside the retained `caller_node_id` (the web UI and CLI show `Removed Node` with the ID if the caller Node was later deleted):

| `triggered_by` | Meaning |
| --- | --- |
| `swamp:auto-deploy` | Automatic production or development policy (includes `caller_node_id`; does not cause a manual-deploy pause). |
| `orbit:auto-deploy` | Existing rows recorded by the PHP automatic runner (still counts as automatic for pause and failure rules). |
| A Node name | Manual deployment or rollback by that Node. |

## One operation at a time

Deployments, rollbacks, deploy-step updates, branch changes, environment operations, Instance removal, and Route changes share the Instance's operation lock. A second deploy or rollback while one is queued or running returns `operation.target_busy` with the active Operation's ID. Instances sharing a Route deploy sequentially rather than in parallel.

Rollback is the one exception: a rollback supersedes a queued deploy (manual or automatic) that no worker has claimed yet. In one transaction, Orbit marks the queued deploy `cancelled` (`error_code: operation.superseded`, `cancel_requested: true`) and admits the rollback. If a worker has already started the deploy, the rollback returns `operation.target_busy`; cancel the deploy with [`operation:cancel`](/cli/operation) or wait for it to finish, then retry.

[Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat release, Git, `.env`, web-root, or source checks; deployment and rollback Operations own release validation.

## The production home

Existing host-based production Instances that have not yet migrated to [Production containers](/reference/production-containers) use a release layout under `/home/<production-user>`:

| Path | Purpose |
| --- | --- |
| `releases/<name>/` | Retained Git checkouts of deployed releases. |
| `.env` | Durable environment file, symlinked from each release's application directory. |
| `env/<directory>/.env` | Durable environment file for an additional [Route with a web root](/reference/routes#web-roots-on-production), symlinked from that directory in each release. |
| `database.sqlite` | Optional SQLite database path (created when seeded by [cloning](/reference/instance-cloning), synced into `.env`). |
| `current` | Symlink to the active release (absent until the first deploy). |

For nested web roots such as `apps/site/public`, each release links `apps/site/.env` to `<home>/.env` using a relative symlink (`../../../../.env`) rather than creating a release-root `.env` link. Additional Route web roots link their own `.env` and grant Caddy access before `current` switches; a release missing a required Route directory fails preparation (or activation on rollback) and keeps the previous selection.

## Why it works this way

These reasons explain the design. Check them before proposing a change.

### A development default is a plain checkout

A development default updates in place (fetch, checkout, steps) rather than maintaining production release directories and `current` symlinks, which would waste disk space and serve stale paths to development Processes and Schedules. Leaving a failed step checked out lets the next retry repair the workspace without rolling back dependencies, while refusing dirty tracked files protects local edits. Seeds point at the default's workspace instead of holding unbounded frozen copies, because setup always runs locked installs afterward.

### Orbit owns releases, you own the steps

Release preparation, switching, and runtime refreshes are identical across applications, so Orbit owns them. Build, migration, and cache commands vary per application, so each Instance or Project defines its own steps without framework guesswork.

### Deploy a branch, not a commit

A manual deploy means "deploy what the branch holds now", while an automatic deploy pins the exact commit that passed `merge_check` and verifies first-parent membership so a newer unchecked push cannot slip in.

### Poll, do not wait for a webhook

Polling branch heads through the Gateway avoids exposing a public webhook endpoint and secret on a private WireGuard control plane. Caching GitHub App installations and read tokens keeps quiet ticks to a single `git ls-remote` with no GitHub API calls.

### Pending checks need no deadline

The walk for a green commit skips pending heads and selects the newest green ancestor descending from the deployed commit, so deploys never block on a slow check and the 30-minute stall alert flags stuck commits instead.

### Only you resume after a pause

A manual deploy or rollback is an explicit operator decision about what should run. A subsequent Git merge should not silently undo it, so only turning `auto_deploy` back on resumes automatic deployments.

### Bounded release retention

Retaining the previous release (`old` in production containers, or up to three releases on legacy host homes) balances instant rollback against disk usage. On legacy host homes, releases still referenced by a running process outrank the limit so cleanup never pulls code out from under active workers.

### Rollback selects code only

Rolling back data or schema changes requires application-specific recovery that Orbit cannot safely guess. Rollback therefore switches code only and leaves databases and shared paths untouched.

### A rollback wins over a queued deploy

A rollback usually responds to a broken release. Cancelling an unclaimed queued deploy lets the rollback run immediately, while refusing to interrupt an in-flight deploy prevents leaving the Instance half-switched.

### Steps as named records

Storing each deploy step by name lets you add, update, reorder, or remove one step without resending the full list or wrestling with shell-quoting issues in repeatable CLI flags.

### Why development has a Gateway precheck

Comparing `seed_commit` against the default branch head in a lightweight minute precheck on the Gateway avoids paying policy-runner startup and API overhead on every quiet minute, entering the policy runner only for defaults that actually have new commits.
