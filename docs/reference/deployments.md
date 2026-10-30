---
title: "Instance releases"
description: "How production Instances build releases and select current code, and how development defaults deploy in their checkout."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Instances/Smoke/**,Instances/ProductionWebRootManager.php,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{StartInstanceDeployAction,StartInstanceRollbackAction,RunAutomaticDeploysAction,RestartInstanceProcessesAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceSmoke*Action,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/{GitHubInstanceSmoke,SmokeArtifactEvidence,RemoteProductionDeployment,DevelopmentCheckoutProgram,ProductionApplicationPaths,ProductionWebRootProgram,RemoteProductionWebRootManager}.php
  - apps/gateway/app/Console/Commands/{AutoDeployCommand,CheckInstanceDeploymentOperationsCommand}.php
  - apps/agent/src/commands/{production,production_tests,production_home,production_home_tests,deploy_step}.rs
  - apps/gateway/app/Http/{Controllers/Api/{InstanceSmokeController,InstanceDeploymentsController,InstanceDeployStepsController,InstanceReleasesController,InstanceRollbacksController,ProjectDevelopmentDeployStepsController},Requests/Projects/*ProjectDevelopmentDeployStepRequest}.php
  - apps/gateway/{app/{Models/{InstanceDeployment,InstanceDeployStep,ProjectDevelopmentDeployStep},Data/Instances/InstanceDeploymentData}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_05_000000_add_caller_node_id_to_instance_deployments}.php}
  - packages/php-sdk/src/{Requests/Projects/*DevelopmentDeployStep*,Responses/{Projects/*DevelopmentDeployStep*,Deployments/InstanceDeploymentResponse}}.php
---

# Instance releases

An Instance on an `app-prod` Node serves code from a release. Swamp deploys the Instance's branch into a fresh release, runs the Instance's deploy steps, and switches the `current` link atomically. The Gateway validates admission and each named Node command, stores deploy-step configuration, and records proven deployment receipts. Environment configuration and an optional SQLite database live outside the releases, so they survive each deployment. [Cloning](/reference/instance-cloning) creates every production Instance, and its first deployment selects the first release. [`instance`](/cli/instance#orbit-instancedeploy) lists the commands.

Deployment execution uses the shared child and receipt contracts. The Gateway refuses admission with `operation.kind_unavailable` when a required owning-family child is unavailable. It never falls back to a PHP deploy executor. Production deployment uses the Process/Schedule installation and restart children, except for the synchronous executor-Node composition described below. PHP requires `node.prepare_php_packages`; a Vite+ build requires `tool.manager_converge`.

Route PHP preparation, including compensation of a failed Route, admits the same package-only child from `instance.prepare_php_runtime` after its PHP plan is recorded. It reuses the selected Node and PHP version, and repeated child admission returns the same Operation. The family completion receipt proves every required descriptor and exact child result for that deployment variant; development never requires production children.

Release this conversion after kit I, kit B/D, the Process/Schedule children, the Node PHP preparation child and `route.converge`, in that order. The deployment kinds also require kit C's enforced list results. Vite+ readiness requires the Tool lane's `tool.manager_converge`. `bin/check-operation-kinds` refuses a release missing these contracts. Deploy orbit-swamp PR #77 before the Gateway release in Orbit PR #1590.

Drain old PHP deploy workers before switching executors. The cutover migration ends queued or waiting legacy deploy and rollback Operations without deployment snapshots as `deployment.interrupted`, finishes their history rows and releases their targets without Node effects. It leaves running PHP Operations and their holds unchanged. Drain those workers before releasing the cutover; the migration must not unlock an Instance while an old worker can still write. It keeps new Swamp intent and terminal history. Route transitions use the converted Route Operations and their bound Instance children.

Completion derives the selected release from proven activation or previous-selection receipts. A lost Node connection does not prevent a failed or cancelled Operation from finishing its history row and releasing its Instance. Preparing a candidate alone never proves that it was selected.

Public deploy log tails are at most 1 KiB and mark truncation; deployment history retains its bounded event stream. PHP preparation receipts report readiness flags. The Gateway keeps the full rendered PHP configuration in Operation state and binds it to the native command input. Operation results stay within the shared 256 KiB limit.

Environment writes use `instance.sync_environment`, with protected material resolved in Gateway memory. Explicit production environment synchronization keeps its synchronous config-cache refresh, PHP cache refresh and Process restart. A failed refresh keeps the pending marker. Route preparation uses `instance.prepare_php_runtime` and `instance.prepare_served_web_roots` for admitted production targets before their associations become active. Route URL-only synchronization accepts the `synchronize-web-root-urls.rollback.<instance_id>` child key after the parent restores its stored web root.

The `app-prod` role decides this layout. `APP_ENV` does not; see [Laravel mode](/reference/environment-variables#laravel-mode).

The Instance deployment execution contract binds the repository, placement, PHP runtime, ordered step digests and required-step flags to admitted intent. Arbitrary commands and environment contents stay out of that snapshot. Keyed transport receipts prove the prepared and activated releases; caller step fields do not prove activation. Before an automatic effect, the Gateway rechecks its policy writer, active role, smoke hold, manual pause and blocked commit under the Instance writer lock. Vite+ preparation reads the proven candidate checkout and resolves protected registry settings only in Gateway memory.

## The production home

Each production Instance has a home, `/home/<production-user>`, with these paths.

| Path | Purpose |
| --- | --- |
| `releases/<name>/` | One retained release: a Git checkout of the deployed branch. |
| `.env` | The durable environment file. Each release's application directory holds a `.env` link to it. |
| `env/<directory>/.env` | The durable environment file of another application directory that a [Route with a web root](/reference/routes#web-roots-on-production) serves. Each release links that directory's `.env` to it. |
| `database.sqlite` | An optional SQLite database. Orbit keeps the path but does not create the file. |
| `current` | A link to the selected release. It is absent until the first deployment. |

A clone leaves the home prepared, with no `current` link. When a clone seeds SQLite, Orbit registers an owned SQLite connection, attaches it under `DB`, and syncs `DB_DATABASE=<home>/database.sqlite` into the production `.env`. Later releases use the same database. [Cloning](/reference/instance-cloning) describes how to seed the file.

The Gateway lists retained releases as the production user from a directory that user can access. A private SSH home does not prevent reading the first clone's empty selection. Failed scans and invalid release receipts still stop the operation.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. A nested root such as `apps/site/public` serves `<home>/current/apps/site/public`; its [application directory](/reference/projects#application-directory) is `<home>/current/apps/site`. The release's `apps/site/.env` links to `<home>/.env`, with a relative target calculated from that depth (in this example, `../../../../.env`). Root `public` keeps the release-root `.env` link with target `../../.env`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

With root `server/web/public`, both the initial clone and later releases link `server/web/.env` with target `../../../../.env`. Orbit does not create a second link at the release root. Release selection, retained-release listing, rollback validation, and [Doctor](/cli/doctor) check the link in that same application directory. Source classification reads that directory's `composer.json` and `artisan`, while ownership and Git identity checks still cover the whole release.

A [Route with a web root](/reference/routes#web-roots-on-production) adds a web root in `current`, such as `apps/docs/public`. Source preparation links the release's `apps/docs/.env` to `<home>/env/apps/docs/.env`, with target `../../../../env/apps/docs/.env`, before the deploy steps run. Activation, for a deployment and for a rollback, checks each such web root and its links, adds a missing `.env` link, and grants Caddy access before it switches `current`. Only active Routes count. An Instance without such a Route uses only its Instance web root.

A new release without such a directory fails at source preparation. Like any other preparation failure, it keeps the earlier selection and leaves the release directory. A rollback to an older release without the directory fails at activation and keeps the earlier selection.

## Shared paths

Production Instances keep shared directories in the Instance home. Before any `before_activation` step, Orbit links each declared path from the new release to `<home>/<path>`. A first deployment creates the persistent directory. If it is empty, Orbit seeds it from the directory in the new release. Later deployments keep its contents and discard the release copy. These operations run as the Instance user. Deploy steps must preserve these links.

The [agent commands](/reference/node-agent#commands) `instance.prepare-release`, `instance.activate-release`, `instance.inspect-release`, `instance.list-releases`, and `instance.prune-releases` do this work. Each starts as root and first checks the layout marker, which root owns. It runs Git and every file change in the home as the Instance user.

Laravel app Projects default to `storage`, relative to the application directory. For root `apps/site/public`, the default is `apps/site/storage`, linked to `<home>/apps/site/storage`. Other Project types default to no shared paths.

Use `orbit instance:update ID --shared-paths='["storage","var/uploads"]'` to replace the paths, `--shared-paths='[]'` to disable sharing, or `--shared-paths=null` to restore the default.

Set `shared_paths` with `PATCH /api/v1/instances/{instance}`, for example `{"shared_paths":["storage","var/uploads"]}`. The list replaces the default. `[]` disables shared paths; `null` restores the Project-type default. Instance responses include the effective `shared_paths`. This setting requires access to the Instance's Node and the same operation lock as deployments. It applies to the next deployment and does not move files in the current release.

Paths are relative to the repository root. Use at most 32 distinct, non-overlapping directory paths, each at most 255 bytes. Components use letters, digits, dots, underscores, and hyphens. Absolute paths, `.` and `..` components, hidden top-level paths, and Orbit's `releases`, `current`, `env`, and `database.sqlite` paths are refused. Source and destination ancestors must not be symlinks. Shared paths cannot replace a web root or its ancestors.

### Public storage

When the application's `storage` directory is shared and its web root is `public`, preparation creates `public/storage -> ../storage/app/public` if the link is absent. Do not add an Artisan `storage:link` step for this layout. An existing link is accepted only when it resolves to that application's `storage/app/public`, either inside the release or at the matching persistent path in the Instance home. All other web-root symlinks, broken links, and symlinks within the public storage directory are refused during preparation, activation, and release inspection.

Activation grants Caddy read and directory access to `storage/app/public` and passage through its parents. It does not grant read access to private uploads or logs. Directories in the public storage tree receive default ACLs so new uploads inherit access.

### Replace a hand-made link-storage step

If the Instance already uses `<home>/storage`, leave that directory and its files in place. Confirm that the effective shared paths include `storage`, then remove the `link-storage` step with `DELETE /api/v1/instances/{instance}/deploy-steps/link-storage` before the next deployment. Orbit reuses the existing directory and creates the public storage link. For a nested application, use its matching persistent path.

If files still live only in the selected release, copy them into the matching persistent directory before deploying. Use an application maintenance window when writes can occur during the copy. Automatic seeding uses the new release's source files, not the selected release's runtime data. Orbit does not change existing production files when this setting is saved.

Prune removes the links with their releases and never follows them into the shared directories. A rollback selects older code and leaves shared data unchanged. A release created before shared paths were enabled keeps its original layout; deploy again to create a release with managed links.

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
- The seed keeps the last commit that deployed. So the automatic deployment tick deploys the commit again after a backoff and reruns the steps, at most three times.

A failed best-effort step (`required: false`) gets a warning that names it and its exit status, and the deployment continues. The output and stored events keep that warning, even when the deployment succeeds or the output reached its storage limit.

A step and Orbit's checkout take the lock that [setup and teardown steps](/reference/instance-setup) hold on the checkout: a `flock` on the checkout directory. While a setup or teardown step runs there, the deployment fails with `instance.lifecycle_busy` and the tick retries after a backoff. A step's processes do not inherit the lock, so a process that a step leaves running cannot hold up the next step. Steps must not change tracked files: the next deployment would refuse the checkout as dirty.

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

The Node agent fetches the branch with `instance.resolve-development-target`, checks the commit out in place with `instance.checkout-development-default`, and runs each step with `instance.run-development-step`, all as the managed account. Checkout refuses tracked changes, which the deploy output lists, and a local branch with commits that the target lacks. A setup or teardown step that runs in the checkout makes each of them refuse with `instance.lifecycle_busy`.

Required steps must pass before Orbit records the commit as deployed. A failed best-effort step (`required: false`) is reported but does not fail the deployment, and the files it wrote stay. Use required steps for installs, builds, and migrations, and best-effort steps for cache warm-up.

### Production deploy steps

Production steps belong to an Instance and run before or after activation.

| Field | Contract |
| --- | --- |
| `name` | Unique within the Instance. 1 through 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. |
| `phase` | `before_activation` (the default) or `after_activation`. |
| `command` | Non-empty UTF-8 of at most 16 KiB, without a NUL byte. |
| `timeout_seconds` | 1 through 900. The default is 300. |

A production step runs as the Instance owner at the release's repository root, through the Node agent's `instance.run-deploy-step`. Its output is redacted and streams live on the operation's channel, as [Live output](/reference/node-agent#live-output) describes.

A new step goes to the end of its phase. `before` or `after` places it next to another step of the same phase. The two fields are exclusive. An Instance has at most 32 steps, and their timeouts add up to at most 3,600 seconds. The Gateway refuses a change that breaks a rule and stores nothing.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deploy-steps` | The steps in phase and placement order. |
| `POST /api/v1/instances/{instance}/deploy-steps` | Creates one step. Requires `name` and `command`. Accepts `phase`, `timeout_seconds`, and `before` or `after`. |
| `PATCH /api/v1/instances/{instance}/deploy-steps/{name}` | Changes `command`, `phase`, `timeout_seconds`, or the placement. |
| `DELETE /api/v1/instances/{instance}/deploy-steps/{name}` | Removes the step. The others keep their order. |

The branch lives on the Instance. `PATCH /api/v1/instances/{instance}` with `branch` sets it. Without one, a deployment uses the Instance's selected branch. A Project default change does not change it.

Deploy-step and branch changes need an access grant to the Instance's Node. The Gateway refuses them for an Instance that is not a production Instance with `deployment_config.unavailable` (409). Activity and errors never contain a step command.

### Vite store prerequisite

Before a JavaScript step (`npm-build`, or a command using `vp`, Node, npm/npx, pnpm, Yarn, Bun, Vite, or Corepack), Swamp runs the `instance.prepare_vp_environment` child to prepare the [Project environment in the vp store](/reference/tools#project-environments-in-the-vite-store) when the Project declares `vite-plus`. The Gateway reads and validates runtime requirements and resolves protected registry settings only in memory. Swamp executes the bound prerequisite before the application command, as the store owner. It records desired versions before installing missing content and verifies the result before allowing the step to start. It does not run package install scripts as the store owner.

The default JavaScript deploy runs `vp install --frozen-lockfile` followed by `vp run build`, through `vp env exec` with recorded exact Node and package-manager versions as the Instance user. That execution reads the shared store but never writes it. A data migration replaces stored legacy default steps containing hard-coded Node and npm paths; runtime execution does not rewrite commands. Customized commands keep their flags, shell operators, and additional build scripts, including SSR builds.

A missing runtime or package manager that cannot be installed fails with `deployment.vp_store_failed`, before the build command runs. Invalid or incompatible Project requirements fail with `deployment.vp_requirements_invalid`. The current production release stays selected. Fix connectivity, store ownership, or Project requirements and retry the deploy; no manual runtime installation is required. Doctor reports recorded store drift without repairing it.

## Deploy through the API

Swamp executes each deployment and rollback as an [operation](/reference/operations). The Gateway keeps admission, deploy-step configuration, target exclusion, history and receipts.

| Request | Body | Result |
| --- | --- | --- |
| `POST /api/v1/instances/{instance}/deploy` | `{}` or `{"commit":"SHA"}` | `202` with an `instance.deploy` operation. Follow it with `GET /api/v1/operations/{operation}`. |
| `POST /api/v1/instances/{instance}/deploy/automatic` | `{"commit":"SHA"}` and `Idempotency-Key` | `202` with the automatic `instance.deploy` receipt. Requires Gateway Node access. |
| `POST /api/v1/instances/{instance}/rollback` | `{"release": "<name>"}` | `202` with an `instance.rollback` operation that selects one retained release. Follow it with `GET /api/v1/operations/{operation}`. |
| `GET /api/v1/instances/{instance}/releases` | none | `releases`, the retained release names, and `selected_release`, which can be null. A development default has none. |

### Pinned commits and automatic callers

`instance:deploy --commit=SHA` sends `{"commit":"SHA"}` to the existing deploy endpoint. Omit `commit` to keep the manual branch-head behavior. SHA is exactly 40 or 64 lowercase hexadecimal characters. The Gateway checks Instance eligibility and the Project's recorded repository identity before admission. The queued source-preparation step checks the managed repository binding and requires the commit on the deployment branch's first-parent chain before environment sync, user steps or activation. Invalid membership ends the operation with `deployment.commit_not_on_branch`; a moved head either still contains the pinned commit or fails. It never substitutes the new head. Development defaults use the Project default branch.

`POST /api/v1/instances/{instance}/deploy/automatic` and MCP `instance-deploy-automatic` start the same `instance.deploy` operation with automatic origin. They require a commit, an `Idempotency-Key`, an eligible production Instance with automatic deployment enabled, and Gateway Node access.

This service contract has no CLI command. Swamp invokes it with its Gateway-peer authority; operator deploys use `instance:deploy --commit=SHA` and keep manual identity and pause behavior. The normal deploy endpoint always keeps the authenticated caller's manual identity, including when the caller has Gateway access. Both reject unknown body fields, including `triggered_by`, `automatic` and `caller_node_id`.

Swamp runs as `swamp serve` on Services. Its Gateway calls use Services' existing active WireGuard peer identity, derived from the connection address, with the existing directed Services-to-Gateway access edge. Gateway access is already fleet-wide authority; it also authorizes this automatic command. Access only to a production Node permits manual deploys but does not permit automatic origin. No per-command permission, new bearer token or client-supplied identity is introduced. Establishing that access and starting a Swamp policy workflow are separate operator actions; this contract changes no live configuration.

The automatic receipt stores the authenticated caller Node ID and pinned commit in operation input, the Instance in its target, and the request key and fingerprint in the operation framework. The fingerprint binds caller and command as well as target and input. The same request returns its existing operation even after completion; another caller, command, Instance or commit with the same key returns `operation.idempotency_conflict`. Manual deploys and other operation kinds keep their existing key semantics across callers.

Under the Instance lock, the automatic operation rechecks enablement, manual pause and rolled-away blocks before effects.

A manual deploy or rollback row pauses it regardless of whether that attempt is running, failed or succeeded. A rollback records its rolled-away block when its outcome is recorded, before history pruning, so resume and history pruning cannot lose it while Swamp is idle. `auto_deploy=true` resumes after the newest manual row and keeps the block. A paused operation ends with `deployment.paused`; a rolled-away commit ends with `deployment.commit_blocked`. A manual action that has already started and still holds the Instance makes a new start busy. A rollback may supersede an unclaimed queued deploy under the existing rules.

These primitives retain the existing operation receipt, history, output, recovery, target exclusion and cooperative cancellation. Swamp owns production policy and executes production and development deployment Operations. Development scheduling keeps its existing admission path.

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
| `process_restart` | yes | `after_activation` |
| `after_activation.<step>` | no | `after_activation` |
| `cleanup` | yes | none: a failed cleanup is a warning |

The admitted configuration stays fixed for the Operation. A changed script, target, release identity or command input cannot replace that intent during execution.

A development default has `source_preparation` and one `before_activation.<step>` for each [development deploy step](#development-deploy-steps). `php_refresh` appears only for an Instance that serves PHP.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`. A failed operation names the step in `failed_step` and the deployment's error code in `error_code`, such as `deployment.step_failed`. Read the full log of the run from its [history row](#history).

Swamp reuses successful keyed receipts after restart. After a lost reply, it reads the keyed receipt on the target before another invocation. A proven receipt supplies the original result; a running or unreadable receipt leaves the Operation pending. An arbitrary deploy script with recorded intent and no proven result ends interrupted; it is never repeated. The failed boundary decides the selected release, as for any failure. A saved completion is replayed after a lost reply without running another Node command.

### The rollback operation

The Gateway validates the request and the access grant, checks that the Instance is a production Instance that retains the release, and answers `202`. The operation's `input` is `{ release }`. A refusal uses the normal JSON error envelope and changes nothing:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `rollback.release_not_retained` | 422 | The Instance does not retain the release. [`instance:release:list`](/cli/instance#orbit-instancereleaselist) lists the releases it retains. A queued deploy stays queued |
| `deployment_config.unavailable` | 409 | The Instance is not a production Instance |
| `operation.target_busy` | 409 | Another operation holds the Instance |
| `operation.queue_unavailable` | 503 | The Gateway has no queue |

The `rollback` step checks the release again, because a deploy's cleanup can remove it after the request. Then the operation fails at `rollback` with `rollback.release_invalid`. A rollback cancels a deploy that no worker has started, as [One operation at a time](#one-operation-at-a-time) describes.

| Step | Resumable | Failed boundary |
| --- | --- | --- |
| `rollback` | yes | `rollback_selection` |
| `activation` | yes | `activation` |
| `php_refresh` | yes | `cache_refresh` |
| `process_restart` | yes | `after_activation` |

The `rollback` step reads the current selection and finds the retained release. It changes nothing. `php_refresh` appears only for an Instance that serves PHP.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`, as for a deploy. `release` is the retained release, and `commit` is its commit. A failed operation names the step in `failed_step` and the error code in `error_code`, such as `rollback.release_invalid`.

A rollback ignores a cancel request once its `activation` step succeeded: it still refreshes PHP and restarts Processes. Before that, a cancel stops it before its next step, and the history row ends `failed` with `deployment.cancelled`.

## Deploy

A deployment reads the branch and the steps once, when it starts. Then it runs these phases in order.

1. **Source preparation.** Swamp asks the Node agent to clone the branch into a new release as the production user, then prepares its shared paths and public storage link.
2. **Environment sync.** Swamp runs `instance.sync_environment` to write the stored configuration into `<home>/.env`, as [synchronization](/reference/environment-variables#synchronize) does. This needs exactly one Route on the Instance. An Instance without a Route fails here with `env.owner_unavailable`.
3. **Before activation.** Swamp runs each `before_activation` step in order, from the new release, as the production user, with a non-interactive shell.
4. **Activation.** The Gateway replaces `current` atomically with a link to the new release.
5. **PHP refresh.** The Gateway reconciles and confirms the dedicated FPM runtime, then resets that service's OPcache and waits for completion.
6. **Process restart.** The Gateway restarts active Instance Processes that are desired `running` and have `restart_on_deploy: true` (the default). Stopped Processes stay stopped.
7. **After activation.** Swamp runs each `after_activation` step in order.
8. **Cleanup.** The Gateway removes old releases, as [Retained releases](#retained-releases) describes. A failed cleanup is a warning in the output, not a failed deployment.

Each Process restart uses the normal Process restart action and reports success or failure in deployment events. A failure stops the deployment before after-activation steps and cleanup. The new release stays selected.

Deployment environment synchronization only writes files. It does not run the explicit env-sync runtime refresh or restart Processes before activation. Explicit [production env-sync](/reference/environment-variables#synchronize) refreshes PHP and uses the same Process restart action afterward, reporting restarted Process IDs in its response.

Orbit skips PHP refresh for an Instance that does not serve PHP. A changed resolved release restarts the dedicated service so its workers enter the new application directory.

To opt out, set `restart_on_deploy: false` in a Process create or update request, or pass `--no-restart-on-deploy` to [`process:create` or `process:update`](/cli/process). This setting applies to production deployments, rollbacks, and a successful explicit production env-sync runtime refresh. For Horizon managed as an Instance Process, keep the default and remove a hand-made `php artisan horizon:terminate` after-activation step with `instance:deploy-step:destroy`. Orbit now restarts that Process before after-activation steps. The normal runtime stop behavior applies.

Orbit runs no migration, Laravel cache clear, dependency install, asset build, or application health check of its own. With no deploy steps, Orbit runs only its runtime operations. The `current` switch is atomic, but the dedicated FPM restart is not zero-downtime: requests can fail briefly while its socket and workers are unavailable, and in-flight requests can be interrupted. Other Instances keep their own PHP services. A confirmed no-op reconciliation does not restart FPM.

Each step has its own timeout. A timeout stops the step's process group and every later step. The whole deployment has a deadline of the step timeouts plus 900 seconds, at most 1,600 seconds, the [deadline of an operation](/reference/operations#how-an-operation-runs). A rollback has a deadline of 900 seconds. When a deadline stops the run, the error code is `deployment.deadline_exceeded`.

## Failures

The failed boundary decides which release stays selected.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The release that `current` selects when the Gateway reads it back from the Node. When that read fails, the release selected before the deployment. |
| PHP refresh, a Process restart, or an `after_activation` step | The new release. |

Orbit never undoes the effects of a step, of the environment sync, or of data changes. You decide how to recover.

Before it prepares a release, the Gateway checks that the production home records the Project's `repository_url` in its release layout marker and in the origin of the selected release. When that check fails, the Gateway reads the recorded URLs to name the cause.

| Error code | Cause | Recovery |
| --- | --- | --- |
| `deployment.repository_rebind_required` | The home records another URL for the same repository, for example the SSH form after the Project moved to HTTPS. | Run `orbit project:update <project> --repository=<url>` with the Project's current URL. It [re-binds the home](/reference/projects#production-instances). |
| `deployment.repository_mismatch` | The home records another repository. | None in place. The home belongs to another repository. |
| `deployment.selection_invalid` | Another selection check failed. | Inspect the home with [Doctor](#inspect-release-placement-with-doctor). |

Rollback and the release list report the same two repository codes.

## Roll back

A rollback selects one retained release through `current`. The Gateway checks that the release is inside `releases/` and that the web root stays inside it. It then switches `current`, reconciles the dedicated FPM runtime, and resets its cache, as a deployment does. A changed resolved release restarts that service with the same brief serving interruption.

After PHP refresh, a rollback restarts the same active, opted-in Instance Processes as a deployment. The `process_restart` step and its output record the results. A restart failure fails the rollback at `process_restart`, with the failed boundary `after_activation`, and leaves the rollback release selected. A rollback fetches nothing, writes no environment, runs no deploy command, and changes no database file.

## Automatic deployments

Swamp runs the production automatic-deploy policy every minute at second 30 (`30 * * * * *`). It discovers enabled, active production Instances on Nodes with an active `app-prod` role from the Gateway. The Gateway retains effects, locks, authorization, and history. Its `orbit:auto-deploy` scheduler runs every minute only for [development defaults](#development-defaults), on the [Gateway queue](/reference/gateway-queue#scheduled-work). The command also accepts the alias `orbit:deploy-development-defaults`.

Each policy tick reads the branch head through the Gateway with `git ls-remote` and the Project's [read credential](/reference/instance-setup#credentialed-git). Production policy uses the [Gateway source contracts](#swamp-policy-contracts); development defaults call the same credentialed reader locally. It keeps the GitHub App installation that covers the repository for up to 60 minutes. It keeps the read tokens for up to 50 minutes, encrypted with the application key. Between token refreshes, each eligible Instance costs one `ls-remote` and no GitHub API call in a quiet minute. A failed read drops the cached installation and tokens.

- When the Instance already runs the commit the tick would deploy, the tick starts nothing and writes no history row.
- Otherwise it starts an [`instance.deploy` operation](#the-deploy-operation) pinned to that commit. The [origin label](#history) identifies Swamp or the Gateway development runner.
- The deployment proves that the branch contains the commit and checks out exactly that commit. It fails with `deployment.commit_not_on_branch` otherwise.
- Pushes that arrive meanwhile wait for the next tick. That tick reads the newest head, so intermediate commits coalesce.
- While another operation holds the Instance, the tick skips it and reports `busy`. Its own operation that has not ended yet is `deploying`.

Operations need the [Gateway queue](/reference/gateway-queue#without-a-queue). Without it, the tick fails with `operation.queue_unavailable` for each Instance that has a commit to deploy, and changes nothing. A head the Gateway cannot read fails the tick for that Instance with `source.head_unavailable`. The other Instances still deploy.

### Automatic development defaults

A default deploys its default branch's head. Its deployed commit is its `seed_commit`. A failed commit retries as the [failure rules](#failures-of-automatic-deployments) describe; for a default every failure is retryable. A new commit deploys at once.

### Production Instances

Swamp decides when a production Instance deploys after you turn automatic deployment on:

```bash
orbit instance:update <instance> --auto-deploy=true
```

The tick starts from the commit of the Instance's newest succeeded deployment or rollback. Until a first deployment succeeds, it reports `not_deployed`; deploy the Instance by hand once. It reads the head of the deployment branch: the `--branch` that `instance:update` set, or the Instance's branch.

When the Project has a `merge_check`, the tick deploys the newest commit that passed it. It finds that commit the way [automatic Gateway releases](/reference/github-app#find-the-newest-green-commit) do. The commit is on the branch's first-parent history, and a GitHub Actions check run of that name passed on exactly that commit. It strictly descends from the deployed commit and is not blocked. It never deploys a red or pending commit. When the head is red, it can deploy an older green commit behind it.

A pending check that passes is normally selected on the next tick, within one minute. A cached failed check is read again on the first tick at least 5 minutes after the failed read. Admission, the operation queue, and deployment steps add time before the commit is live.

| Result | Meaning |
| --- | --- |
| `up_to_date` | The Instance runs the head. |
| `started` | The tick started a deployment of the commit it names. |
| `deploying` | The tick's deployment has not ended yet. |
| `gate_pending` | The head's check has not finished, and no older commit is green. The tick reads the check again each minute at second 30. |
| `gate_failed` | The head's check failed with no older green commit. Recheck on the first tick at least 5 minutes after that read. |
| `no_green_commit` | No commit may deploy: the head is blocked, or it does not descend from the deployed commit, for example after a force push. |
| `commit_failed` | The head is blocked, and no gate applies: a development default, or a Project without a `merge_check`. |
| `busy` | Another operation holds the Instance. |
| `backing_off` | The commit failed, and its retry is not due yet. |
| `paused` | A manual deployment or a rollback paused automatic deployment. |
| `not_deployed` | No deployment of the Instance has succeeded yet. |
| `failed` | The tick could not read the head or the check, or could not start the deployment. `error_code` names why. |

The green-commit walk reads the head's own check run too, so one walk answers both questions. The tick walks again only when the head changed, while the head's check has not finished, or on the first tick at least 5 minutes after it failed. A `no_green_commit` answer stays until the head, the deployed commit, or the blocked commits change.

Without a `merge_check`, the tick deploys every new head. `instance:update` accepts that and warns about it. A `merge_check` is read through the [GitHub App](/reference/github-app). A Project that reads through the GitHub CLI, or is not on `github.com`, cannot gate: `instance:update --auto-deploy=true` refuses it with `instance.auto_deploy_gate_unreadable` while it has a `merge_check`.

#### Failures of automatic deployments

A failure before any of the Project's own steps ran, at `operation`, `preparation`, or `environment`, is transient. The tick retries on the first tick at least 10 minutes after the first failure, then at least 20 minutes after the second failure. After three failed attempts, the commit is blocked.

These failures are lasting, and the commit is blocked at once:

- A cancelled deployment.
- A commit that is not on the branch.
- A home that records another repository.
- A home that records another URL for the same repository.
- An Instance that cannot be deployed.
- A failure in a deploy step or later.

A blocked commit does not deploy automatically again. A new commit deploys at once. Failures before the newest manual deployment do not count.

#### Pause

A manual deployment or a rollback after `auto_deploy` was last set pauses the Instance, whether it succeeded or not, also while it still runs. A rollback that waits for a worker has no history row yet. Its operation holds the Instance, so the tick reports `busy`, starts nothing, and does not count the commit as stalled. The Instance stays paused until you set `--auto-deploy=true` again; a new commit does not resume it.

Resuming continues from the commit that is live then. The commit a rollback moved away from is blocked. The tick reads it from the deployment history, so it counts also when you resume or deploy by hand before a tick saw the rollback. The tick also keeps it in `auto_deploy_state.blocked`, so it stays blocked after its rows leave the history.

A blocked commit stays blocked. It never deploys automatically again, until a newer commit lands on the branch: that commit deploys, and the blocked one is behind it.

A rollback that came after the tick queued the deployment wins while no worker has started the deployment: the rollback cancels it, as [One operation at a time](#one-operation-at-a-time) describes. Once a worker has started it, the rollback is busy.

A rollback or manual deployment can also end between the tick's read of the history and the start of its deployment. So the deployment checks for a pause again under the Instance lock. A pause found there ends the operation with `deployment.paused` and deploys nothing. A first attempt writes no history row. An attempt that a worker resumes after a lost worker ends its row with `deployment.paused`, which does not count as a failure of the commit.

**Visibility.** Each automatic deployment has its operation and its [history row](#history) with the [recorded origin](#history). `instance:show` shows the last result, its commit, the head, and a pause. When a result other than `up_to_date`, `deploying`, or `gate_pending` starts, the tick writes one Activity entry, `auto_deploy.<result>`, with the command `orbit:auto-deploy`. A [release alert](/reference/gateway-recovery#release-alerts) with the target `instance:<id>` reports a failed automatic deployment once per commit, a commit or a pending head on the first tick after it waited at least 30 minutes, once per commit, and a pause.

**One worker.** Development defaults, production Instances, and manual deployments share the one `operations` worker, as [One worker for every operation](/reference/operations#one-worker-for-every-operation) describes. A tick's deployment waits in the queue while another deployment runs. Instances behind one Route deploy one after another, not together.

## Swamp policy contracts

The [ADR 0238 trial](/decisions/0238-trial-two-swamp-slices-before-a-wider-move) exposes policy evidence through the Gateway. Swamp never reads GitHub, the database, or a notifier directly. These contracts require Gateway Node access. Policy starts stay on API/MCP; receipt resolution also has an operator CLI command.

| Request | Evidence or effect |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-source/head` | A fresh credentialed `head` of the recorded deployment branch. Works without a GitHub gate. |
| `GET /api/v1/instances/{instance}/deployment-source/commits?base=<sha>` | The current GitHub branch `head` and up to 20 first-parent `commits`, newest first, before `base`. Each has `sha`, `parents`, and `descends_from_deployed`. |
| `GET /api/v1/instances/{instance}/deployment-source/checks?commit=<sha>` | The Project's configured `gate` and exact latest check `runs`, with name, head SHA, App slug, status, and conclusion. Empty runs never prove success. |
| `GET /api/v1/instances/{instance}/deploy/automatic/receipt?request_key=<key>&commit=<sha>` | Exact keyed admission lookup, bound to caller, automatic command, Instance, and commit. Returns `request_key`, `found`, `operation`, and retained `deployment`, when available. Conflicting input returns `operation.idempotency_conflict`. |
| `PUT /api/v1/instances/{instance}/auto-deploy/policy` | Publish the public policy projection with the observed `resumed_after`, `last_deployment_id`, and `history_version`. A changed cursor or history returns `instance.auto_deploy_observation_changed`. |
| `POST /api/v1/instances/{instance}/auto-deploy/events` | Admit an `instance.policy_event` operation with required `Idempotency-Key`. One `activity` event records a notable policy result; one `failed`, `paused`, or `stalled` event uses the Gateway's release alert notifier. Follow the operation to read its delivery receipt. |

The source listing contains at most 100 reachable commits, and the walk returns at most 20 first-parent candidates. `reached_base` says the walk reached `base`, including when the last candidate names it as its first parent. `truncated` says the candidate bound or a missing parent cut the walk short. Reaching the repository root without `base` returns both flags false. Swamp can fail closed on a truncated walk. Each returned candidate carries an exact ancestry comparison with `base`. The source reads accept full 40- or 64-character commit SHAs.

The commits and checks reads require the Project's GitHub App access. They return `instance.auto_deploy_gate_unreadable` for another source provider and `instance.auto_deploy_gate_unavailable` when the upstream read fails. They expose evidence, not a green-commit decision. Swamp applies the policy and checks that the walk's head matches its fresh head. A moved branch does not silently change a selected commit.

Instance list/show and broadcasts expose `auto_deploy.policy_owner`, `auto_deploy.resumed_after`, `auto_deploy.blocked`, and `auto_deploy.history_version`. The cursor is set only by `instance:update --auto-deploy=true`. The block list combines durable rollback blocks and retained rolled-away history without waiting for a tick. When automatic deployment is off, only permanent smoke blocks are returned and the history version is null; these reads make no policy-history query. The smoke failure contract also records a permanent block before recovery; those blocks remain visible even when automatic deployment is disabled. Swamp keeps its retry and gate state itself. Deployment list/show expose `kind` (`deploy` or `rollback`); origin is recorded in `triggered_by`, with the authorized automatic caller in `caller_node_id`.

A found automatic receipt is the original operation, including its bound caller in `input.caller_node_id`, status, result, and error. Follow that operation and read fresh deployment history before deciding what happened. A missing receipt means only that no admission is recorded at the read: an earlier request might still be in flight. It never authorizes a retry. A worker-lost outcome or a terminal operation without required effect evidence stays unresolved. Keyed operations are retained. Neither lookup nor outcome resolution starts work.

### Clear an unresolved admission hold

Pause new decisions with `orbit instance:update <instance> --auto-deploy=false`. Read the exact receipt using the original Swamp caller, Instance, commit, and request key. Never resend the automatic POST to discover its outcome.

Use `POST /api/v1/instances/{instance}/deploy/automatic/resolve` (MCP `instance-deploy-automatic-resolve`) with `request_key` and `commit`. By default it resolves the authenticated Node's key. A Node with Gateway access may pass `caller_node_id` to resolve for the original caller. The CLI is `orbit instance:deploy:automatic:resolve <instance> <request_key> <commit> --caller-node=<original-node-id>`; omit the flag for your own key. Activity records the acting Node and the original caller. Admission and resolution serialize under the same Instance database lock.

If admission is absent, this writes a retained cancelled operation with `operation.admission_fenced`, zero attempts, no target lock, and no deployment. Its unique request identity fences a delayed admission: every later POST with that key returns the cancelled receipt. Swamp may resolve its own stale keys automatically. No operator action is needed for normal lost-response recovery. Repeating resolution returns the same receipt. A different caller, Instance, or commit conflicts.

If admission already exists, resolution returns its receipt without cancelling or rewriting it. Follow an active operation until it ends. Swamp clears its intent on the zero-attempt fenced receipt and may use a fresh key on its next tick. Other holds clear only from terminal effect evidence; resolution never starts a replacement deploy.

A terminal worker-lost receipt without effect evidence still needs investigation: stop the old host writer, inspect the live release, then reconcile through a manual deploy or rollback before resuming. Do not clear Swamp storage by hand. After reconciliation, resume with `orbit instance:update <instance> --auto-deploy=true`; the rollback block remains. A missing receipt alone never permits resume or a new request key.

To switch policy back:

1. Disable `auto_deploy` on the affected Instances.
2. Wait until no automatic operation is running or queued.
3. Revert [orbit#1555](https://github.com/hardimpactdev/orbit/pull/1555) (code-only, no migrations).
4. Re-enable `auto_deploy` (PHP owns again).

### One policy writer during the trial

Swamp decides production automatic deployments. The code-owned writer is `swamp`; Instance list/show exposes it as `auto_deploy.policy_owner`. Swamp discovers eligible Instances with `GET /api/v1/instances?auto_deploy=true` and does nothing when the owner is `gateway`. The filter uses the same eligibility rule as automatic admission: automatic deployment enabled, active Instance, production placement, and an active `app-prod` role. An inactive or absent `app-prod` role refuses admission with `instance.auto_deploy_role_inactive`. The worker checks this rule again before effects. Unfiltered Instance listing still includes ineligible Instances. Automatic admission, publication, and event admission refuse with `instance.auto_deploy_policy_owner` under PHP ownership. The deploy and event workers check ownership again before effects. There is no environment flag or runtime switch.

The cutover release removes the PHP production policy loop. Development defaults retain their PHP scheduler. Deploy Swamp first: it stays idle until the Gateway reports Swamp ownership. Drain old PHP policy work before activating the Gateway release. Swamp reconstructs policy and retries from the public projection and Gateway history. Alert deduplication starts in Swamp: the owner accepts one possible repeat alert at cutover. Orbit exposes no stored alert-key contract.

Publication updates only the public projection in `auto_deploy_state`, preserves Gateway rollback blocks, and broadcasts the Instance DTO used by API, MCP, CLI, and web. The project owner waived the slice 1 shadow phase because Hauzer and Mealou have no users; its 14-day live window starts at this cutover.

Publication uses a short database transaction without the deployment host lock. The history version hashes the stored fields of retained deployment rows, including `caller_node_id`, without events or display names. Adding a deployment or finishing an existing deployment changes it, even when the newest ID stays the same. Renaming or removing a caller Node does not change it. The recorder writes the terminal history and rollback blocks in one transaction, with the Instance row locked and refreshed before changing policy state. It preserves a projection that publication already committed.

It accepts only the cursor and history version Swamp observed, so a later manual action cannot be hidden by a stale publication. Resume admission remains Gateway-owned; Swamp executes deployment effects. Swamp owns selection, retries, gate caches, alert intents, and publication order; it must serialize ticks for each Instance.

Event operations use a separate `instance_policy` target lock. A running deployment does not prevent a stall alert. The delivery step cannot resume after worker loss, because a webhook may already have left the Gateway. Replaying its admission key returns the same operation; it does not deliver twice. Inspect partial notifier receipts and uncertain outcomes instead of creating another event key.

The Gateway derives alert targets and repositories from the Instance. Both policy callers use the same history selectors for the current pause and latest terminal automatic failure after resume, including the required commit for a failure alert. They also use the same notable Activity writer. Callers cannot choose an unrelated notifier target.

## History

The Gateway records one row for each deployment and each rollback. It writes the row when a worker first starts the deploy or rollback operation, and completes it when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `kind` (`deploy` or `rollback`), `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by`, `caller_node_id`, and `events`. `events` holds the phase and output events of the run, with at most 128 KiB of output. A deployment saves them after each step, so a resumed deployment keeps the events up to the last step that ended. The `cleanup` phase marks release cleanup. A development deployment records `commit` and leaves `release` and `selected_release` null.

**Release order:** First merge and deploy [orbit-swamp PR #55](https://github.com/hardimpactdev/orbit-swamp/pull/55) that accepts both `orbit:auto-deploy` and `swamp:auto-deploy` in policy, admission, holder, and receipt checks. Only then merge and release the Orbit change that emits `swamp:auto-deploy` ([Orbit PR #1561](https://github.com/hardimpactdev/orbit/pull/1561)). Swamp’s exact match on the old label otherwise treats its own deploy as manual, pauses automatic deployment, and rejects admissions and receipts. This prerequisite changes Swamp; Gateway admission and policy behavior stay unchanged.

The web, CLI, API, and MCP show the stored origin. Deployment and operation reads expose nullable `caller_node_id` and `caller_node_name` separately from the readable `triggered_by` label. The web and CLI show the current caller Node name. If the Node has been removed, they show “Removed Node” with its retained ID.

Swamp deployment rows copy the authorized Gateway peer's Node ID from the operation when the row starts. The ID is retained if the Node is renamed or removed. `caller_node_name` is the current name, or null if there is no caller or the Node has been removed. Manual deployments and the Gateway development runner have no automatic caller ID. Operations also retain the bound ID in `input.caller_node_id`.

| `triggered_by` | Meaning |
| --- | --- |
| `swamp:auto-deploy` | Swamp production policy admitted through the automatic endpoint. `caller_node_id` identifies the authorized Gateway peer. Policy event operations and fenced automatic admission receipts use the same origin. |
| `orbit:auto-deploy` | The PHP automatic runner's label, still used for development defaults. Existing rows keep this label, including early Swamp admissions. No labels or caller IDs are backfilled. |
| A Node name | A manual deployment or rollback by that Node. |

Both automatic labels count as automatic for pause and failure selection. A Swamp deployment never creates a manual-deploy pause. A rollback still pauses automatic deployment regardless of its label. Clients cannot set either automatic label or the caller ID on a deploy request.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployments` | The Instance's rows, newest first, without `events`. |
| `GET /api/v1/deployments/{deployment}` | One row with `events`. |

## One operation at a time

Deployment, rollback, deploy-step changes, and branch changes share the Instance's operation lock with environment operations, Instance removal, and Route changes. A competing request waits or receives a busy error. A deployment or a rollback holds the lock while its job runs; an interrupted job releases it after its running command stops.

A deploy or rollback operation also holds the Instance from its start request to its end. A second start answers `operation.target_busy` with the first operation's ID, also while the first one waits in the queue.

A rollback is the one exception. It wins over a deploy of the Instance that no worker has started, manual or automatic:

- The deploy ends `cancelled`.
- The deploy's `error_code` is `operation.superseded`, and its `cancel_requested` is true.
- The rollback takes the Instance.

A deploy that a worker started keeps the Instance, also while it waits to resume after a lost worker. The rollback then answers `operation.target_busy` with the deploy's ID. Cancel the deploy with [`operation:cancel`](/cli/operation), or wait for it to end. Then start the rollback again.

The Gateway cancels the deploy and creates the rollback in one database transaction. When a worker claims the deploy first, the deploy runs, and the rollback is busy. One operation holds the Instance in every case. When the Gateway then cannot queue the rollback's job, the rollback fails with `operation.dispatch_failed`, and the deploy stays cancelled; start the rollback again.

## Inspect release placement with Doctor

[Doctor](/cli/doctor) checks each production Instance against its home. It reports a missing or wrongly owned home, a broken `current` link, a selected release outside `releases/`, and a web root that leaves the release. It checks the `.env` link of the default application directory only. A home without `current` is healthy before the first deployment. Doctor accepts an older release after a rollback, and a release whose branch has moved on.

## Retained releases

When preparing a candidate, Orbit keeps the selected release's Node and package-manager references, even when its build fails. Retained releases keep those references for rollback too. Release cleanup drops references only for releases it takes out of the retained set; a release kept for running processes keeps its runtimes.

A successful production deployment keeps at most 3 releases in the home: the new selection, the selection before it, and the newest other release. `initial` counts as the oldest, and later releases sort by the creation time in their names. It removes the rest, and the output names each one. So [`instance:rollback`](/cli/instance#orbit-instancerollback) can return to the previous selection and one other release. A rollback removes nothing. A failed deployment removes nothing either; the next successful one does.

The `release-layout` marker is owned by root and records the original layout. Validation authenticates that marker and the selected release without requiring `initial` to remain.

Releases that only stopped Processes used can be removed once they fall outside the retention limit. If a Process opts out, uses a fixed old-release path, or leaves child processes running, it can still use an older release. Cleanup keeps every release that is the working directory, root, or executable of a running process of the production user, also beyond the limit. The output names each such release; stop or restart its remaining processes to free it. When cleanup cannot read where a live process works, it removes nothing and reports a failed cleanup.

Cleanup removes only folders that pass the release checks of the listing. It first renames a release to `releases/.pruned-<name>`, so the release leaves the list at once, and then deletes that folder. Cleanup deletes the release's own files and shared-path links. It does not follow those links or delete persistent shared directories in the Instance home. Files in unshared directories go with the release. A folder that a stopped cleanup left is deleted by the next one. A partial folder that fails the release checks stays.

[Instance removal](/reference/instance-removal) removes the serving setup, production account, home, and source state. Releases, environment files, and SQLite data in that home are deleted with it. Back up any application data you need before removal.

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

Automatic policy reads branch heads through the Gateway instead of receiving a GitHub push webhook. A webhook would need a public endpoint and a webhook secret on a Gateway that otherwise answers only WireGuard peers. Swamp uses the source-read contracts; PHP development defaults and automatic Gateway releases retain their native readers. A quiet minute costs one `git ls-remote`, and a pending check costs a few GitHub API reads, well within the App's rate limit. The polling delay is up to one minute; failed-check caches, retry backoff, and the operation queue can add time.

Conditional requests with an ETag were rejected: a quiet minute already makes no GitHub API call, and the cache keeps token requests rare.

### Pending checks need no deadline

The green-commit walk skips a pending commit and walks back to the newest green commit that descends from the deployed one. So no deployment waits for a check to finish, and a deadline for pending checks was rejected. The stall alert after 30 minutes reports a commit that does not deploy.

### Only you resume after a pause

A manual deployment or a rollback is a person's decision about what runs. A merge is not a decision to undo it. So a new commit does not resume automatic deployment; only setting `auto_deploy` again does.

### One operations worker for every deployment

Automatic deployments and rollbacks run as operations on the one `operations` worker, like manual deployments. One worker keeps one deployment at a time in the fleet and lets each Instance's lock and a lost worker work the same way for every deployment. A second worker for automatic deployments was rejected: it would let two deployments run at once and would need its own lock and recovery. A deployment or a rollback of one Instance can wait for another Instance's deployment, and Instances behind one Route deploy in turn.

### Three releases per home

Each release holds a full checkout with its dependencies, so the count decides the disk use. Three keep the current code, the way back, and one more step back. The previous selection outranks newer releases that never went live, because it is the release a rollback needs first.

A release that a running process uses outranks the limit: deleting it would pull the code, the dependencies, and the configuration cache out from under that process.

### Rollback selects code only

Older code can need a data recovery that only the application knows. So a rollback switches code and nothing else. Coupling it to a database rollback is a rejected alternative.

### A rollback wins over a queued deploy

A rollback undoes a bad release, often while users see errors. A deploy that no worker started has changed nothing yet, so the rollback cancels it instead of waiting behind it. A deploy that runs has already changed the Instance. A rollback that stopped it would leave it at a step boundary, half done. So the rollback refuses, and a person decides whether to cancel the deploy or let it end. The rule is the same for manual and automatic deploys.

### Steps as named records

Each step has its own create, update, and destroy, so you can change one step without resending the others. A single replace-all document and a repeatable step flag are rejected alternatives. A command with spaces, colons, or quotes breaks a flag separator.

### Clone is the only way in

Every production Instance comes from a clone, and its first deployment builds the release layout. Orbit does not convert a production home that serves code from its checkout.

The production home exists before the first release is selected. Deployment and runtime consumers must not treat that pre-release home as a selected release.

## Production smoke

Swamp can opt a Project into production smoke after a successful production deploy. The Gateway exposes the selected release and dispatches the repository's own GitHub Actions browser-test workflow against its public HTTPS origin. This feature adds no schedule or opt-in. Separate keyed recovery endpoints can block a proven failing SHA and request a guarded rollback; they do not repair, resume or merge. Swamp owns baseline admission, deadlines and acceptance policy. This page owns the Gateway wire contract. The private [Swamp implementation reference](https://github.com/hardimpactdev/orbit-swamp/blob/main/docs/production-smoke-contract.md) is supporting material.

`GET /api/v1/instances/{instance}/smoke/context` and MCP `instance-smoke-context` require Gateway Node access. They return `instance_id`, `project_id`, `repository_url`, `production`, `base_urls`, `selected` and `retained_releases`. Context reads the actual selected retained release on the Node and matches a successful deploy or rollback history row. `selected` is null without matching evidence; otherwise it contains `deployment_id`, the full 40-character `commit` and `release`. A production Instance must be active on an active Node with an active app-prod role and no active app-dev role. URLs are HTTPS origins of active public Project-owned Routes targeting that Instance. A development Instance cannot admit smoke.

The complete feature ships as a pair with [orbit-swamp#52](https://github.com/hardimpactdev/orbit-swamp/pull/52). The context endpoint is read-only and works without dispatch permission. Before opt-in, the Gateway operator must complete the [GitHub App permission upgrade](/reference/github-app#production-smoke-permission). No live registration, installation or Project setting changes during release preparation.

### Dispatch and receipt

`POST /api/v1/instances/{instance}/smoke` and MCP `instance-smoke` require Gateway Node access and an `Idempotency-Key`. The JSON object has exactly `deployment_id`, `commit` (40 lowercase hex characters), `release`, `base_url` (an active Route HTTPS origin), `workflow` (`.github/workflows/<name>.yml` or `.yaml`) and `ref` (the workflow's Git ref). A missing repository or App installation is `422 instance.smoke_invalid`; GitHub outages are `503 instance.smoke_github_unavailable`. Invalid input, ownership, eligibility or missing Actions write returns `422 instance.smoke_invalid`. A changed selected deployment returns `409 instance.smoke_selection_changed`. Other access, queue and idempotency errors keep their existing codes. Admission binds the authenticated caller, Instance and full Request, plus the repository and Route. It returns `202` with an `instance.smoke` Operation targeting `instance_smoke`/Instance ID, separate from the deploy lock.

The Gateway rechecks selection before its first send, resolves the workflow identity and workflow-ref SHA separately from the tested commit, then records send intent before the GitHub POST. It never retries a send with recorded intent, including after a lost reply or worker restart. GitHub receives only `commit`, `deployment_id` and `base_url`. Waiting releases the queue worker. Bounded reads reconcile exactly one workflow_dispatch run matching the workflow, resolved ref SHA, deployment/SHA run name and admission time. Missing or ambiguous matches stay unresolved.

`GET /api/v1/instances/{instance}/smoke/receipt?request_key=...` and MCP `instance-smoke-receipt` look up the original caller/Instance/key. They never dispatch. The response has `request_key`, `found`, `operation` and `smoke`; a missing receipt has false, null and null. A result repeats every original Request field and adds `state`, nullable `run_id`, `run_attempt`, `workflow_sha`, `run_url` and `conclusion`, nonnegative `tests`, `failures`, `errors`, `skipped`, redacted UTF-8 `output` (at most 64 KiB), and `artifacts` with name and safe GitHub URL. All fields are present, including nulls. Keyed operations and evidence are retained.

A pass requires a completed successful run and matching attempt-specific target JSON and JUnit from the repository's `production-smoke-<run_id>-<attempt>` artifact, with positive tests and zero failures, errors and skips. The JSON binds checkout commit, deployment ID, URL, run ID and attempt. Unknown, expired, corrupt or mismatched evidence, uncertain dispatch, and missing or ambiguous runs report pending or unknown, never failed. Only JUnit failures or errors report failed. Clean JUnit with a failure, timed_out or cancelled GitHub conclusion reports unknown with `instance.smoke_run_unsuccessful` on the Operation. Skipped-only or empty test evidence is unknown.

The Gateway reports evidence. Swamp alone enforces its original deadline, missing-result policy and fresh selection check; new selection or configuration never rewrites recorded evidence.

A new admission for another deployment requests cancellation of any older non-terminal smoke on that Instance and retains its receipt as unknown. A running read honours cancellation at its next boundary. Until that holder yields, admission returns `409 operation.target_busy`; Swamp retries the identical key and body on the follow-up observation, after the native ten-second wait. A busy refusal never means that tests failed. Preparing a send retries at most three times before intent exists and refreshes the workflow ref SHA on each attempt. A definite dispatch refusal reports unknown immediately with `instance.smoke_dispatch_refused`; an uncertain send is never repeated.

Artifact transport outages remain pending until a later read or the observation deadline. Digest, archive and schema mismatches are terminal unknown with `instance.smoke_evidence_invalid`. The Gateway allows 60 seconds of clock skew when matching creation time.

The repository workflow must name its run `Smoke <deployment_id> <commit>` and publish `production-smoke-<run_id>-<run_attempt>`. Its zip contains `storage/framework/testing/smoke-target.json` and `storage/framework/testing/smoke.xml`. Target JSON string fields are `commit`, `deployment_id`, `base_url`, `run_id` and `run_attempt`. JUnit must be UTF-8 without a document type or external entities. Output is stored once in the retained observation; step and Operation results contain only state metadata.

### Wire contract and errors

All three endpoints require Gateway Node access and use the normal `{data, meta}` envelope. POST requires `Idempotency-Key`. IDs and counts are integers, SHA values have 40 lowercase hex characters, and every field below is present, including nulls. `OperationData` is the [operation record](/reference/operations#the-record), including its bound input, target and error code.

```typescript
type Selection = { deployment_id: number; commit: string; release: string };
type Context = {
  instance_id: number; project_id: number; repository_url: string;
  production: boolean; base_urls: string[];
  selected: Selection | null; retained_releases: string[];
};
type Request = Selection & { base_url: string; workflow: string; ref: string };
type Result = Request & {
  state: "pending" | "passed" | "failed" | "unknown";
  run_id: number | null; run_attempt: number | null; workflow_sha: string | null;
  run_url: string | null; conclusion: string | null;
  tests: number; failures: number; errors: number; skipped: number;
  output: string; artifacts: { name: string; url: string }[];
};
type Receipt = {
  request_key: string; found: boolean;
  operation: OperationData | null; smoke: Result | null;
};
```

Observation ends 720 seconds after admission: Swamp's 600-second budget plus 120 seconds of queue slack. GitHub rate-limit responses, including primary and secondary 403 limits, stay pending. Other 401/403 responses end unknown with `instance.smoke_github_auth`. The Gateway reuses the encrypted cached Actions-read credential across polls. Invalid JUnit reports expose zero counts. Inactive workflows, path mismatches and refs without a full SHA end immediately with `instance.smoke_invalid` and a safe reason.

| Status or outcome | Code | Meaning |
| --- | --- | --- |
| 422 admission refusal | `instance.smoke_invalid` | Invalid target/configuration, missing repository/installation or Actions write |
| 503 admission refusal | `instance.smoke_github_unavailable` | Retryable GitHub outage; no operation admitted |
| 409 admission refusal | `operation.target_busy` | Prior holder has not yielded; retry the identical admission |
| Unknown receipt | `instance.smoke_run_unsuccessful` | Clean JUnit with an unsuccessful GitHub conclusion |
| Unknown receipt | `instance.smoke_github_auth` | GitHub denied a read without rate-limit evidence |
| Unknown receipt | `instance.smoke_observation_expired` | The 720-second observation limit expired |


### Verified smoke failure and guarded rollback

This contract pairs with [orbit-swamp#57](https://github.com/hardimpactdev/orbit-swamp/pull/57). Live recovery stays disabled. No Project or Schedule is enabled. The later deploy-writer hold, alerts, restored smoke and conditional resume require separate work and an explicitly authorized production proof.

All routes below require Gateway Node access, normal JSON envelopes and positive integer IDs. `Selection` retains the definition above. `Failure` adds `smoke_request_key` to Selection. `Rollback` has exactly `failure_key`, `expected: Selection`, `target: Selection` and `target_smoke_request_key`. Both POSTs require `Idempotency-Key`. The block key is `smoke-failure.<instance>.<deployment>`; rollback appends `.rollback`.

| Route under `/api/v1/instances/{instance}/smoke` | MCP | Data |
| --- | --- | --- |
| `POST /failure` | `instance-smoke-failure` | 200 Block |
| `GET /failure/receipt?request_key=...` | `instance-smoke-failure-receipt` | 200 BlockReceipt |
| `POST /rollback` | `instance-smoke-rollback` | 202 instance.rollback Operation |
| `GET /rollback/receipt?request_key=...` | `instance-smoke-rollback-receipt` | 200 RollbackReceipt |

Block is `{request_key, instance_id, failure: Failure, blocked: true}`. BlockReceipt is `{request_key, found, block: Block | null}`. RollbackReceipt is `{request_key, found, operation: OperationData | null, deployment: (Selection & {status: running | succeeded | failed}) | null}`. Required nullable fields remain present. A missing receipt has `found: false` and null evidence. GET never creates effects. Identical POST retries return the original receipt; changed caller, Instance or full body refuses with `operation.idempotency_conflict`.

The Gateway independently reads the caller-bound stored smoke receipt. It requires the exact deployment SHA/release, admitted repository and active public Project Route, positive non-skipped tests, failure/error counts, and the run/attempt/workflow/target evidence already verified by the smoke reader. A workflow conclusion alone cannot block. Missing, pending, unknown or cancelled evidence cannot block or roll back. A newer selected release does not invalidate an already verified failure.

Under the existing Instance deploy-writer lock, one database transaction stores the immutable keyed failure in `auto_deploy_state.smoke_failures` and its SHA in `auto_deploy_state.blocked`. Smoke blocks are exempt from the ordinary recent rollback-block bound. The receipt and block survive selection changes, target pruning, recovery failure and resume. Recording failure does not pause or resume automation.

Rollback requires that caller's recorded failure and block, exact current `expected` selection, and the requested target's prior passed smoke plus retained SHA and release. The target has a lower deployment ID and a different SHA and release from the failed deployment. It never chooses another target.

Admission and queued execution check these guards under the existing Instance deploy-writer lock. The native `instance.rollback` operation uses its existing Instance target lock, resumable steps, history and manual-pause semantics. After activation, a resumed operation checks the selected target instead of the old expected release. Success also verifies the selected target SHA/release under the lock; its rollback deployment ID is new. No revert commit or data change is made. No automatic resume occurs.

Changed selection is `409 instance.smoke_selection_changed`; a pruned or mismatched retained target is `409 instance.smoke_rollback_target_missing`. Unverified failure or ownership is `422 instance.smoke_failure_invalid`; invalid rollback proof/body is `422 instance.smoke_rollback_invalid`. Existing access, queue and operation-lock errors remain. Failed/cancelled rollback or unreadable receipts require assistance using the original key and target; the permanent block remains.

### Smoke holds and conditional recovery

The Gateway stores a smoke hold separately from operator pause state in `auto_deploy_state.smoke_control`. Both automatic-deploy admission and queued execution refuse `instance.smoke_held`. A hold never disables `auto_deploy`, clears a failed-SHA block, or changes main. Manual recovery remains available.

Gateway peers use `POST /api/v1/instances/{instance}/smoke/control` with an `Idempotency-Key` and `{action: hold|alert|release, hold_key, expected: {deployment_id,commit,release}, smoke_request_key: string|null, rollback_request_key: string|null}`. Replies contain `{request_key,instance_id,action,hold_key,held,resumed,reason}`; `GET .../control/receipt?request_key=...` returns `{request_key,found,control}`. Keys bind the caller, Instance and complete body. Only an active Orbit production Instance with a public Route qualifies.

A hold snapshots operator controls and existing manual pause. An alert raises release assistance and the configured notification; partial delivery stays recorded and can be retried. Missing or ambiguous smoke evidence keeps the release live and the hold in place. Release requires a fresh, verified passing receipt for the exact current selection.

It removes only its own hold and assistance. Resume advances the manual-pause cursor only when automation was enabled and unpaused at acquisition, operator controls did not change, and every subsequent manual row belongs to this recovery. Other manual work and explicit operator controls win. Blocks on failed commits remain permanent.

A smoke rollback records whether it superseded a queued automatic deployment. If so, `POST .../smoke/redeploy` accepts the same control body with `action: redeploy` and admits a native pinned `instance.deploy` for the restored selection. Admission and worker execution verify the hold, caller, selected target and completed owned rollback. Its keyed receipt at `GET .../redeploy/receipt?request_key=...` includes the operation and deployment. Smoke the resulting selected release before release and conditional resume. Failure at any recovery step retains the hold and requires assistance; it never tries successively older releases.

### Repair tasks after smoke failure

Gateway peers create one repair group for a recorded production smoke failure with `POST /api/v1/instances/{instance}/smoke/repair`, `{failure_key}`, and required `Idempotency-Key: <failure_key>.repair`. `GET .../repair/receipt?request_key=...` returns the original receipt without creating work. Creation returns `{request_key,instance_id,project_id,failure_key,group_id,task_id}` inside `data`; lookup returns `{request_key,found,repair}`. The key binds caller, Instance and complete input. A replay returns the same group even after it ends or recovery changes. A new failed deployment has a new key. Missing or ambiguous smoke results cannot create a repair.

Admission independently verifies the recorded block, failed deployment identity, hosted ownership and executed failing JUnit. It creates the group and saves its receipt in one transaction under the Instance writer lock. Repair creation does not depend on rollback success and never changes deploy holds, pause state, code or blocks. Tasks must be enabled. The normal task creation action chooses the configured default agent. The group uses external orchestration with one repair child and the normal task factory; it starts no Gateway task tick.

The brief includes the failed SHA and release, smoke output and artifact links, the source commit, available associated PR and CI evidence, recovery receipt links and the migration compatibility constraint. Output is redacted and bounded; full smoke evidence remains in its original receipt. Unavailable GitHub context is stated and does not prevent a repair. The repair must add a regression test and the missing smoke coverage. Swamp records its factory handoff before starting the existing factory and reconciles its state after restarts. Live creation remains disabled with the recovery effects gate until the production pilot is authorized.

## Runtime composition at cutover

Production deploy and rollback Operations use the owning Process and Schedule children. Instances on the configured Swamp executor Nodes keep synchronous Process and Schedule composition through a bound Gateway command, so the executor does not depend on itself for runtime recovery. The admitted intent fixes this selection. Public environment synchronization and the retained clone environment caller remain synchronous; deployment parents explicitly use `instance.sync_environment`.

After the PHP package child succeeds, each deploy and rollback publishes the Orbit PHP runtime module through `php.configure-runtime` before pool convergence and cache refresh. This preserves the 64 MiB post and upload limits and the shared OPcache settings.


## Missing executor and Gateway revert

Deploy and rollback Operations use the generic [executor health](/reference/operations#executor-health) checks for missing workers, stalled progress and repeated reclaims. Assistance does not change a target hold or mark a commit as failed.

To undo this cutover, prepare a revert of Orbit PR #1590 on the Gateway. Drain Gateway deploy workers and stop new deploy admission before switching executors. While the current Gateway still exposes the Swamp contracts, let running Swamp Operations finish, including their runtime children after proven activation. Keep Services and its deployed workflows available until that work settles. Never clear a hold while a worker can still write.

Cancel queued Swamp deploy and rollback Operations through the Operation API. Confirm their terminal status and released Instance holds, then release the Gateway revert and restart native deploy workers. A queued Operation must never reach the restored PHP executor with Swamp-only intent. Reverting the executor leaves application code, database state and deployment history in place. Resume normal automatic admission after the queued work is clean and native workers run the reverted Gateway release.
