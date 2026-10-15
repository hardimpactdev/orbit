---
title: "Instance releases"
description: "How production Instances build releases and select current code, and how development defaults deploy in their checkout."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Instances/ProductionWebRootManager.php,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{DeployInstanceAction,DeployDefaultInstanceAction,StartInstanceDeployAction,RunAutomaticDeploysAction,RollbackInstanceAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionDeployment,RemoteDevelopmentDeployment,DevelopmentCheckoutProgram,ProductionApplicationPaths,ProductionWebRootProgram,RemoteProductionWebRootManager}.php
  - apps/gateway/app/Console/Commands/AutoDeployCommand.php
  - apps/gateway/app/Http/Streaming/**
  - apps/gateway/app/Http/{Controllers/Api/{InstanceDeploymentsController,InstanceDeployStepsController,InstanceReleasesController,InstanceRollbacksController,ProjectDevelopmentDeployStepsController},Requests/Projects/*ProjectDevelopmentDeployStepRequest}.php
  - apps/gateway/app/Models/{InstanceDeployment,InstanceDeployStep,ProjectDevelopmentDeployStep}.php
  - packages/php-sdk/src/{Requests,Responses}/Projects/*DevelopmentDeployStep*.php
---

# Instance releases

An Instance on an `app-prod` Node serves code from a release. The Gateway deploys the Instance's branch into a fresh release, runs the Instance's deploy steps, and switches the `current` link atomically. Environment configuration and an optional SQLite database live outside the releases, so they survive each deployment. [Cloning](/reference/instance-cloning) creates every production Instance, and its first deployment selects the first release. [`instance`](/cli/instance#orbit-instancedeploy) lists the commands.

The `app-prod` role decides this layout. `APP_ENV` does not; see [Laravel mode](/reference/environment-variables#laravel-mode).

## The production home

Each production Instance has a home, `/home/<production-user>`, with these paths.

| Path | Purpose |
| --- | --- |
| `releases/<name>/` | One retained release: a Git checkout of the deployed branch. |
| `.env` | The durable environment file. Each release's application directory holds a `.env` link to it. |
| `env/<directory>/.env` | The durable environment file of another application directory that a [Route with a web root](/reference/routes#web-roots-on-production) serves. Each release links that directory's `.env` to it. |
| `database.sqlite` | An optional SQLite database. Orbit keeps the path but does not create the file. |
| `current` | A link to the selected release. It is absent until the first deployment. |

A clone leaves the home prepared, with no `current` link. The application must point its SQLite configuration at `<home>/database.sqlite` itself. Orbit has no placeholder for that path. [Cloning](/reference/instance-cloning) describes how to seed the file.

The Gateway lists retained releases as the production user from a directory that user can access. A private SSH home does not prevent reading the first clone's empty selection. Failed scans and invalid release receipts still stop the operation.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. A nested root such as `apps/site/public` serves `<home>/current/apps/site/public`; its [application directory](/reference/projects#application-directory) is `<home>/current/apps/site`. The release's `apps/site/.env` links to `<home>/.env`, with a relative target calculated from that depth (in this example, `../../../../.env`). Root `public` keeps the release-root `.env` link with target `../../.env`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

With root `server/web/public`, both the initial clone and later releases link `server/web/.env` with target `../../../../.env`. Orbit does not create a second link at the release root. Release selection, retained-release listing, rollback validation, and [Doctor](/cli/doctor) check the link in that same application directory. Source classification reads that directory's `composer.json` and `artisan`, while ownership and Git identity checks still cover the whole release.

A [Route with a web root](/reference/routes#web-roots-on-production) adds a web root in `current`, such as `apps/docs/public`. Source preparation links the release's `apps/docs/.env` to `<home>/env/apps/docs/.env`, with target `../../../../env/apps/docs/.env`, before the deploy steps run. Activation, for a deployment and for a rollback, checks that each such web root exists without a link, adds a missing `.env` link, and grants Caddy access before it switches `current`. Only active Routes count. An Instance without such a Route sends the same commands as before, byte for byte.

A new release without such a directory fails at source preparation. Like any other preparation failure, it keeps the earlier selection and leaves the release directory. A rollback to an older release without the directory fails at activation and keeps the earlier selection.

## Development defaults

A `default` Instance on an `app-dev` Node is a plain Git checkout that Orbit keeps at the newest commit of the Project's default branch. Other development Instances on the Node start from it. `orbit instance:deploy ID`, the deployment API (`POST /api/v1/instances/{instance}/deploy` with `{}`), and the MCP tool `instance-deploy` can deploy a default by hand. Each starts an [operation](#deploy-through-the-api). The Project's default branch supplies the target commit; production branch overrides do not apply.

The [automatic deployment tick](#automatic-deployments) deploys a default each time its default branch has a new commit. Only one deployment runs per Instance, including manual requests.

A deployment runs in the checkout itself, in two phases.

**Source preparation.** Orbit fetches the default branch and checks out the fetched commit on that branch. The fetch runs as the managed user against the Project repository URL; see [credentialed Git](/reference/instance-setup#credentialed-git). Untracked and ignored files stay, so dependencies, caches, `.env` files, and databases carry over.

The exception is a directory that the old commit has and the new commit removes, such as a package. Git would leave that directory's untracked and ignored files, for example its `node_modules`. Orbit deletes them before the checkout, so a [seed](#seeds) copy finds only directories that the commit has.

- When tracked files have uncommitted changes, Orbit refuses with `deployment.checkout_dirty`. The output lists those files, and the checkout stays unchanged.
- When the local default branch has commits that the fetched commit does not contain, Orbit refuses with `deployment.branch_diverged` and changes nothing. A checkout would drop those commits.
- When the checkout would overwrite an untracked file, Git refuses it.

**Steps.** The Project's [development deploy steps](#development-deploy-steps) run in list order in the checkout. They report the `before_activation` phase. There is no activation, because the checkout is what the Instance serves.

When every required step passes, Orbit records the commit as the default's `seed_commit`. When a required step fails, the later steps do not run and the deployment fails.

- The checkout stays at the new commit with the files the steps left. Orbit does not roll it back.
- The seed keeps the last commit that deployed. So the automatic deployment tick deploys the commit again after 10 minutes and reruns the steps.

A failed best-effort step (`required: false`) gets a warning that names it and its exit status, and the deployment continues. The output and stored events keep that warning, even when the deployment succeeds or the output reached its storage limit.

A step and Orbit's checkout take the lock that [setup and teardown steps](/reference/instance-setup) hold on the checkout: a `flock` on the checkout directory. While a setup or teardown step runs there, the deployment fails with `instance.lifecycle_busy` and the tick retries after 10 minutes. A step's processes do not inherit the lock, so a process that a step leaves running cannot hold up the next step. Steps must not change tracked files: the next deployment would refuse the checkout as dirty.

Explicit environment synchronization writes the checkout's `.env` files, so the application sees new values at once. A Route's web root serves files from the checkout, and a deployment does not change the Route.

A deployment records that the default's Route projection is pending before it converges the Route, and clears that mark when the converge succeeds. A tick reconverges a visitable default's Route only while the mark is set, so a crash or a failed converge is repaired on the next tick. An unchanged tick with a completed projection does not take the [projection lock](/reference/routes#coordinate-publication), because a full converge rebuilds the Node's PHP-FPM pools, Caddy, and DNS and blocks every other Route operation while it runs.

### Seeds

A new development Instance on the same Node starts from the default: Orbit records the default's checkout as its `seed_path` and the default's `seed_commit`, and creates a linked worktree of the default's repository at that commit. [Setup steps](/reference/instance-setup#run-setup) receive the two values as `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` and copy dependency folders from the checkout. The checkout can change while they copy, because a deployment can run at the same time. A copied folder is a quick start, not a matching set: setup must still run its locked installs. A default's own setup gets no seed.

## Deploy steps

Development deploy steps run at the checkout's repository root, and production deploy steps at the release's repository root, not at a nested application's directory. For root `apps/site/public`, an Artisan step must say `cd apps/site && php artisan migrate --force`. Changing the web root does not change the scope of a repository-owned command.

A deploy step is a named command that runs during a deployment. Each production Instance stores its own steps. A Project stores a separate ordered development deploy list, shared by its development deployments and independent of setup and teardown. Storing a step does not start a deployment.

### Development deploy steps

Use `project:dev-deploy-step:list`, `project:dev-deploy-step:create`, `project:dev-deploy-step:update`, and `project:dev-deploy-step:destroy`, with `--project=ID`. The API collection is `/api/v1/projects/{project}/dev-deploy-steps`; GET lists, POST creates, and PATCH or DELETE on `/{name}` changes or removes one step. MCP exposes the same operations.

Each step has `name`, `command`, `timeout_seconds`, and `required`. Names use lowercase letters, digits, and hyphens, with a letter or digit at each end, and are at most 63 characters. Commands are nonempty UTF-8 strings of at most 16 KiB without NUL bytes. Timeouts are 1–900 seconds, default 300. `required` is a JSON boolean, default `true`; the CLI accepts `--required=true` or `--required=false`. PATCH accepts any of command, timeout, required, or placement; omitted fields stay unchanged.

A new step appends unless `before` or `after` names another step in this Project's development deploy list. The fields are exclusive. Updates without placement keep their position. Names cannot be renamed. Each list permits at most 32 steps and 3,600 seconds of total timeout. Unknown names, duplicate names, invalid fields, unknown placement, self-placement, and limit violations are refused without changing the list. Node access follows the Project's owning Node. Activity records never include commands.

Required steps must pass before Orbit records the commit as deployed. A failed best-effort step (`required: false`) is reported but does not fail the deployment, and the files it wrote stay. Use required steps for installs, builds, and migrations, and best-effort steps for cache warm-up.

### Production deploy steps

Production steps belong to an Instance and run before or after activation.

| Field | Contract |
| --- | --- |
| `name` | Unique within the Instance. 1 through 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. |
| `phase` | `before_activation` (the default) or `after_activation`. |
| `command` | Non-empty UTF-8 of at most 16 KiB, without a NUL byte. |
| `timeout_seconds` | 1 through 900. The default is 300. |

A new step goes to the end of its phase. `before` or `after` places it next to another step of the same phase. The two fields are exclusive. An Instance has at most 32 steps, and their timeouts add up to at most 3,600 seconds. The Gateway refuses a change that breaks a rule and stores nothing.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | The steps in phase and placement order. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step. Requires `name` and `command`. Accepts `phase`, `timeout_seconds`, and `before` or `after`. |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{name}` | Changes `command`, `phase`, `timeout_seconds`, or the placement. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{name}` | Removes the step. The others keep their order. |

The branch lives on the Instance. `PATCH /api/v1/instances/{instance}` with `branch` sets it. Without one, a deployment uses the Instance's selected branch. A Project default change does not change it.

Deploy-step and branch changes need an access grant to the Instance's Node. The Gateway refuses them for an Instance that is not a production Instance with `deployment_config.unavailable` (409). Activity and errors never contain a step command.

## Deploy through the API

A deployment runs as an [operation](/reference/operations) on the Gateway queue. A rollback still runs inside its request and streams its progress.

| Request | Body | Result |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | `{}` | `202` with an `instance.deploy` operation. Follow it with `GET /api/v1/operations/{operation}`. |
| `POST /api/v1/instances/{instance}/rollback` | `{"release": "<name>"}` | Selects one retained release and streams its progress. |
| `GET /api/v1/instances/{instance}/releases` | none | `releases`, the retained release names, and `selected_release`, which can be null. A development default has none. |

### The deploy operation

The Gateway validates the request and the access grant, checks that the Instance can be deployed, and answers `202`. A refusal uses the normal JSON error envelope: `deployment_config.unavailable` (409) for an Instance that is neither a production Instance nor an active development default, `operation.target_busy` (409) while another operation holds the Instance, and `operation.queue_unavailable` (503) without the Gateway queue.

The operation's steps follow the phases below. Each name is a phase, or a phase and a deploy step's name:

| Step | Resumable | Failed boundary |
| --- | --- | --- |
| `source_preparation` | yes | `preparation` |
| `environment_sync` | yes | `environment` |
| `before_activation.<step>` | no | `before_activation` |
| `activation` | yes | `activation` |
| `php_refresh` | yes | `cache_refresh` |
| `after_activation.<step>` | no | `after_activation` |
| `cleanup` | yes | none: a failed cleanup is a warning |

A development default has `source_preparation` and one `before_activation.<step>` for each [development deploy step](#development-deploy-steps). `php_refresh` appears only for an Instance that serves PHP.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`. A failed operation names the step in `failed_step` and the deployment's error code in `error_code`, such as `deployment.step_failed`. Read the full log of the run from its [history row](#history).

A lost worker resumes a resumable step and fails any other step with `operation.worker_lost`, as [Recover a lost worker](/reference/operations#recover-a-lost-worker) describes. The failed boundary decides the selected release, as for any failure.

### The rollback stream

After it validates the request and the access grant, the rollback answers `application/x-ndjson`. A refusal before that uses the normal JSON error envelope. Each line is one event of at most 32 KiB with `type`, an increasing `sequence`, and the `request_id`.

| Event type | Fields |
| --- | --- |
| `phase` | `phase` is `rollback`. |
| `output` | `stream` is `stdout` or `stderr`. `data_base64` holds at most 16 KiB of output. |
| `result` | `status` is `succeeded` or `failed`, with `failed_step`, `error_code`, and `selected_release`, each nullable. This is always the last line. |

`failed_step` names the boundary that failed: `activation`, `cache_refresh`, `rollback_selection`, or `operation`. An error after the stream opens ends the stream with a failed `result` and no second HTTP error.

The Gateway flushes output while a command runs. Before each phase event, it writes one whitespace byte every 10 milliseconds for 250 milliseconds. That lets it notice a closed connection through Caddy and PHP-FPM. It notices a disconnect only when it writes, so a silent command can run until it writes, exits, or times out. Then the Gateway starts no further step and sends no result. It never replays events or rolls back by itself.

## Deploy

A deployment reads the branch and the steps once, when it starts. Then it runs these phases in order.

1. **Source preparation.** The Gateway clones the repository into a new release as the production user and checks out the latest commit of the branch. A branch that moves later does not change this release.
2. **Environment sync.** The Gateway writes the stored configuration into `<home>/.env`, as [synchronization](/reference/environment-variables#synchronize) does. This needs exactly one Route on the Instance. An Instance without a Route fails here with `env.owner_unavailable`.
3. **Before activation.** The Gateway runs each `before_activation` step in order, from the new release, as the production user, with a non-interactive shell.
4. **Activation.** The Gateway replaces `current` atomically with a link to the new release.
5. **PHP refresh.** The Gateway reconciles and confirms the dedicated FPM runtime, then resets that service's OPcache and waits for completion.
6. **After activation.** The Gateway runs each `after_activation` step in order.
7. **Cleanup.** The Gateway removes old releases, as [Retained releases](#retained-releases) describes. A failed cleanup is a warning in the output, not a failed deployment.

Orbit skips this phase for an Instance that does not serve PHP. A changed resolved release restarts the dedicated service so its workers enter the new application directory.

Orbit runs no migration, Laravel cache clear, dependency install, asset build, or application health check of its own. With no steps, a deployment runs no application command. The `current` switch is atomic, but the dedicated FPM restart is not zero-downtime: requests can fail briefly while its socket and workers are unavailable, and in-flight requests can be interrupted. Other Instances keep their own PHP services. A confirmed no-op reconciliation does not restart FPM.

Each step has its own timeout. A timeout stops the step's process group and every later step. The whole deployment has a deadline of the step timeouts plus 900 seconds, at most 1,600 seconds, the [deadline of an operation](/reference/operations#how-an-operation-runs). A rollback never exceeds its request's 570-second command deadline. When a deadline stops the run, the error code is `deployment.deadline_exceeded`.

## Failures

The failed boundary decides which release stays selected.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The release that `current` selects when the Gateway reads it back from the Node. When that read fails, the release selected before the deployment. |
| PHP refresh or an `after_activation` step | The new release. |

Orbit never undoes the effects of a step, of the environment sync, or of data changes. You decide how to recover.

Before it prepares a release, the Gateway checks that the production home records the Project's `repository_url` in its release layout marker and in the origin of the selected release. When that check fails, the Gateway reads the recorded URLs to name the cause.

| Error code | Cause | Recovery |
| --- | --- | --- |
| `deployment.repository_rebind_required` | The home records another URL for the same repository, for example the SSH form after the Project moved to HTTPS. | Run `orbit project:update <project> --repository=<url>` with the Project's current URL. It [re-binds the home](/reference/projects#production-instances). |
| `deployment.repository_mismatch` | The home records another repository. | None in place. The home belongs to another repository. |
| `deployment.selection_invalid` | Another selection check failed. | Inspect the home with [Doctor](#inspect-release-placement-with-doctor). |

Rollback and the release list report the same two repository codes.

## Roll back

A rollback selects one retained release through `current`. The Gateway checks that the release is inside `releases/` and that the web root stays inside it. It then switches `current`, reconciles the dedicated FPM runtime, and resets its cache, as a deployment does. A changed resolved release restarts that service with the same brief serving interruption. A rollback fetches nothing, writes no environment, runs no step, and changes no database file.

## Automatic deployments

The Gateway runs `orbit:auto-deploy` every minute, a job on the [Gateway queue](/reference/gateway-queue#scheduled-work). The command `orbit:deploy-development-defaults` is its old name and still works. One tick serves every [development default](#development-defaults) and every production Instance with `auto_deploy` on.

For each Instance, the tick reads the branch head on the Gateway with `git ls-remote` and the Project's [read credential](/reference/instance-setup#credentialed-git). It keeps the GitHub App installation and its read tokens for up to 50 minutes, so a quiet minute costs one `ls-remote` and no GitHub API call. A failed read drops them.

- When the Instance already runs the commit the tick would deploy, the tick starts nothing and writes no history row.
- Otherwise it starts an [`instance.deploy` operation](#the-deploy-operation) pinned to that commit, with `triggered_by` `schedule`.
- The deployment proves that the branch contains the commit and checks out exactly that commit. It fails with `deployment.commit_not_on_branch` otherwise.
- Pushes that arrive meanwhile wait for the next tick. That tick reads the newest head, so intermediate commits coalesce.
- While another operation holds the Instance, the tick skips it and reports `busy`. Its own operation that has not ended yet is `deploying`.

Operations need the [Gateway queue](/reference/gateway-queue#without-a-queue). Without it, the tick fails with `operation.queue_unavailable` for each Instance that has a commit to deploy, and changes nothing. A head the Gateway cannot read fails the tick for that Instance with `source.head_unavailable`. The other Instances still deploy.

### Automatic development defaults

A default deploys its default branch's head. Its deployed commit is its `seed_commit`. After a failed automatic deployment, the tick deploys the same commit again after 10 minutes (`backing_off`). A new commit deploys at once.

### Production Instances

A production Instance deploys automatically after you turn it on:

```bash
orbit instance:update <instance> --auto-deploy=true
```

The tick starts from the commit of the Instance's newest succeeded deployment or rollback. Until a first deployment succeeds, it reports `not_deployed`; deploy the Instance by hand once. It reads the head of the deployment branch: the `--branch` that `instance:update` set, or the Instance's branch.

When the Project has a `merge_check`, the tick deploys the newest commit that passed it. It finds that commit the way [automatic Gateway releases](/reference/github-app#find-the-newest-green-commit) do. The commit is on the branch's first-parent history, and a GitHub Actions check run of that name passed on exactly that commit. It strictly descends from the deployed commit and has not failed an automatic deployment. It never deploys a red or pending commit. When the head is red, it can deploy an older green commit behind it. A commit deploys within about 2 minutes after its check passes.

| Result | Meaning |
| --- | --- |
| `up_to_date` | The Instance runs the head. |
| `started` | The tick started a deployment of the commit it names. |
| `deploying` | The tick's deployment has not ended yet. |
| `gate_pending` | The head's check has not finished, and no older commit is green. The tick reads the check again each minute. |
| `gate_failed` | The head's check failed, and no older commit is green. |
| `no_green_commit` | The head passed, but no commit may deploy: it failed a deployment, or it does not descend from the deployed commit, for example after a force push. |
| `commit_failed` | Without a `merge_check`: the head failed its automatic deployment. |
| `busy` | Another operation holds the Instance. |
| `backing_off` | The commit failed for a transient cause less than 10 minutes ago. |
| `paused` | A rollback paused automatic deployment. |
| `not_deployed` | No deployment of the Instance has succeeded yet. |
| `failed` | The tick could not read the head or the check, or could not start the deployment. `error_code` names why. |

The tick reads the check runs only when the head changed, or while the head's check has not finished. A settled answer stays until the head, the deployed commit, or the list of failed commits changes.

Without a `merge_check`, the tick deploys every new head. `instance:update` accepts that and warns about it. A `merge_check` is read through the [GitHub App](/reference/github-app). A Project that reads through the GitHub CLI, or is not on `github.com`, cannot gate: `instance:update --auto-deploy=true` refuses it with `instance.auto_deploy_gate_unreadable` while it has a `merge_check`.

**Failures.** A failure before any of the Project's own steps ran, at `operation`, `preparation`, or `environment`, is transient: the tick deploys the commit again after 10 minutes. A cancelled deployment, a commit not on the branch, a home that records another repository, and a failure in a deploy step or later are not. The commit then does not deploy automatically again. A new commit deploys at once, and a manual deployment clears every earlier failure.

**Rollback.** A rollback after `auto_deploy` was last set pauses the Instance, and the tick never overrides it. It stays paused until you set `--auto-deploy=true` again; a new commit does not resume it. Resuming continues from the commit the rollback selected. A manual deployment does not pause: it deploys the branch head, and the tick continues from that commit.

**Visibility.** Each automatic deployment has its operation and its [history row](#history) with `triggered_by` `schedule`. `instance:show` shows the last result, its commit, the head, and a pause. When a result other than `up_to_date`, `deploying`, or `gate_pending` starts, the tick writes one Activity entry, `auto_deploy.<result>`, with the command `orbit:auto-deploy`. A [release alert](/reference/gateway-recovery#release-alerts) with the target `instance:<id>` reports a failed automatic deployment once per commit, a commit that waited 30 minutes to deploy once per commit, and a pause.

**One worker.** Development defaults, production Instances, and manual deployments share the one `operations` worker, as [One worker for every operation](/reference/operations#one-worker-for-every-operation) describes. A tick's deployment waits in the queue while another deployment runs. Instances behind one Route deploy one after another, not together.

## History

The Gateway records one row for each deployment and each rollback. It writes the row when the run starts, which for a deployment is when a worker first starts its operation, and completes it when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by` (the calling Node's name, or `schedule` for an automatic development deployment), and `events`. `events` holds the phase and output events of the run, with at most 128 KiB of output. A deployment saves them after each step, so a resumed deployment keeps the events up to the last step that ended. The `cleanup` phase marks release cleanup. A development deployment records `commit` and leaves `release` and `selected_release` null.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row with `events`. |

## One operation at a time

Deployment, rollback, deploy-step changes, and branch changes share the Instance's operation lock with environment operations, Instance removal, and Route changes. A competing request waits or receives a busy error. A deployment holds the lock while its job runs; an interrupted job releases it after its running command stops.

A deploy operation also holds the Instance from its start request to its end, so a second deploy request answers `operation.target_busy` with the first operation's ID, also while the first one waits in the queue.

## Inspect release placement with Doctor

[Doctor](/cli/doctor) checks each production Instance against its home. It reports a missing or wrongly owned home, a broken `current` link, a selected release outside `releases/`, and a web root that leaves the release. It checks the `.env` link of the default application directory only. A home without `current` is healthy before the first deployment. Doctor accepts an older release after a rollback, and a release whose branch has moved on.

## Retained releases

A successful production deployment keeps at most 3 releases in the home: the new selection, the selection before it, and the newest other release. `initial` counts as the oldest, and later releases sort by the creation time in their names. It removes the rest, and the output names each one. So [`instance:rollback`](/cli/instance#orbit-instancerollback) can return to the previous selection and one other release. A rollback removes nothing. A failed deployment removes nothing either; the next successful one does.

A deployment does not [restart Processes](/cli/process#orbit-processrestart), so a running Process, such as a queue worker, can still work in an older release. Cleanup keeps every release that is the working directory, root, or executable of a running process of the production user, also beyond the limit. The output names each such release; restart its Process to free it. When cleanup cannot read where a live process works, it removes nothing and reports a failed cleanup.

Cleanup removes only folders that pass the release checks of the listing. It first renames a release to `releases/.pruned-<name>`, so the release leaves the list at once, and then deletes that folder. The release's own files go with it, such as old logs in its `storage/`. A folder that a stopped cleanup left is deleted by the next one. A partial folder that fails the release checks stays.

[Instance removal](/reference/instance-removal) removes `current` and the serving setup, and keeps `releases/`, `.env`, `env/`, `database.sqlite`, and the local PHP-FPM tuning for recovery.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A development default is a plain checkout

A default serves development, so it updates in place like any working checkout: fetch, check out, and run the steps. Releases, a `current` link, snapshots, and pruning are what make a production switch atomic and reversible. A development default needs neither, and that machinery cost disk space, broke when a release lost its Git link, and served a stale checkout to Processes and Schedules that ran outside the release. Release layouts stay where something is served to users: the Gateway's own releases and production homes.

A failed step leaves the new commit checked out instead of rolling back. A rollback would need the old dependencies too, and the automatic deployment tick reruns the steps, so a passing retry repairs the checkout. Refusing uncommitted tracked changes keeps someone's edit from being discarded; untracked files carry the dependencies, so the checkout keeps them.

Seeds name the checkout, not a frozen copy. Setup always runs locked installs after it copies, so a copy taken during a deployment only costs install time. Leases that kept frozen releases alive for other Instances are a rejected alternative: they grew without bound and blocked cleanup.

### Orbit owns releases, you own the steps

Release preparation, the switch, and the PHP-FPM refresh are the same for every application, so Orbit does them. The commands before and after the switch differ for each application, so each Instance stores its own. Orbit does not guess steps from the framework.

### Deploy a branch, not a commit

A manual deployment means "deploy what the branch holds now". So it takes no commit, and two manual deployments of one branch can produce different code. An automatic deployment is the exception: it deploys the commit that passed the gate, so it pins that commit, and the branch must contain it. Without the pin, a newer, unchecked head could deploy in place of the checked commit.

### Poll, do not wait for a webhook

The Gateway reads the branch head every minute instead of receiving a GitHub push webhook. A webhook would need a public endpoint and a webhook secret on a Gateway that otherwise answers only WireGuard peers. The tick reuses the paths that development defaults and automatic Gateway releases already use. A quiet minute costs one `git ls-remote`, and a pending check costs a few GitHub API reads, well within the App's rate limit. The price is up to one minute of delay.

### One operations worker for every deployment

Automatic deployments run as operations on the one `operations` worker, like manual ones. One worker keeps one deployment at a time in the fleet and lets each Instance's lock and a lost worker work the same way for every deployment. A deployment of one Instance can wait for another's, and Instances behind one Route deploy in turn.

### Three releases per home

Each release holds a full checkout with its dependencies, so the count decides the disk use. Three keep the current code, the way back, and one more step back. The previous selection outranks newer releases that never went live, because it is the release a rollback needs first.

A release that a running process uses outranks the limit: deleting it would pull the code, the dependencies, and the configuration cache out from under that process.

### Rollback selects code only

Older code can need a data recovery that only the application knows. So a rollback switches code and nothing else. Coupling it to a database rollback is a rejected alternative.

### Steps as named records

Each step has its own create, update, and destroy, so you can change one step without resending the others. A single replace-all document and a repeatable step flag are rejected alternatives. A command with spaces, colons, or quotes breaks a flag separator.

### Clone is the only way in

Every production Instance comes from a clone, and its first deployment builds the release layout. Orbit does not convert a production home that serves code from its checkout.

The production home exists before the first release is selected. Deployment and runtime consumers must not treat that pre-release home as a selected release.
