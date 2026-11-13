---
title: "Instance releases"
description: "How production Instances build releases and select current code, and how development defaults deploy in their checkout."
covers:
  - apps/gateway/app/Domain/{Instances/Deployment/**,Projects/*DeployStep*.php}
  - apps/gateway/app/Actions/Instances/{StartInstanceDeployAction,StartInstanceRollbackAction,RestartInstanceProcessesAction,InstanceDeploymentConfigResolver,UpdateInstanceAction,ListInstanceReleasesAction,ListInstanceDeploymentsAction,*InstanceDeployStep*Action}.php
  - apps/gateway/app/Infrastructure/Instances/{DevelopmentCheckoutProgram,ProductionApplicationPaths,ProductionWebRootProgram}.php
  - apps/agent/src/commands/{production,production_tests,production_home,production_home_tests,deploy_step,dev_deploy,dev_deploy_tests}.rs
  - apps/gateway/app/Http/{Controllers/Api/{InstanceDeploymentsController,InstanceDeployStepsController,InstanceReleasesController,InstanceRollbacksController,ProjectDevelopmentDeployStepsController},Requests/Projects/*ProjectDevelopmentDeployStepRequest}.php
  - apps/gateway/{app/{Models/{InstanceDeployment,InstanceDeployStep,ProjectDevelopmentDeployStep},Data/Instances/InstanceDeploymentData}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_05_000000_add_caller_node_id_to_instance_deployments}.php}
  - swamp/extensions/behaviors/{automatic-deploy/**,operations/development-deploy/**}
  - packages/php-sdk/src/{Requests/Projects/*DevelopmentDeployStep*,Responses/{Projects/*DevelopmentDeployStep*,Deployments/InstanceDeploymentResponse}}.php
---

# Instance releases

An [Instance container's default deployment](/reference/development-containers#processes) runs its Project commands as `orbit`, repairs workspace group permissions before and after those commands, installs its development Processes and takes a new seed. The seed stops and starts every running unit, so each worker runs the new commit.

Checkout development deployment uses one native Swamp root on 209 and official SSH to the app-dev Node. The Gateway captures public intent and names for private execution copies. Fetch, checkout checks, cleanup, checkout, Vite preparation, each Project command and final inspection are separate native steps. The shared terminal reader records the result and history; development steps do not read intent or report progress to the Gateway.

Production deployment uses the same native root on 209 with a release layout and the admitted Project account ([chart](/cli/instance/deploy)). Every Node action is one official `@swamp/ssh` argv or an official `@swamp/cfgmgmt` behavior; no step calls the Gateway, and no polled executor remains. Rollback stays production-only, and development rollback refuses `deployment_config.unavailable` because checkouts have no retained releases.

Swamp runs production commands as the admitted `orbit-app-<ProjectID>` account. It never asks the agent to act.

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

The Gateway records retained releases from terminal deployment results. Cleanup considers only those recorded releases and retains a release when a running process uses it or the process scan fails.

The web root is the Instance root, or else the Project root, inside `current`. A root such as `public` serves `<home>/current/public`. A nested root such as `apps/site/public` serves `<home>/current/apps/site/public`; its [application directory](/reference/projects#application-directory) is `<home>/current/apps/site`. The release's `apps/site/.env` links to `<home>/.env`, with a relative target calculated from that depth (in this example, `../../../../.env`). Root `public` keeps the release-root `.env` link with target `../../.env`. Caddy resolves the `current` link before it passes a script path to PHP-FPM, so a request after a switch loads its PHP files from the new release.

With root `server/web/public`, both the initial clone and later releases link `server/web/.env` with target `../../../../.env`. Orbit does not create a second link at the release root. Release selection, retained-release listing and rollback validation check the link in that same application directory. Source classification reads that directory's `composer.json` and `artisan`, while ownership and Git identity checks still cover the whole release.

A [Route with a web root](/reference/routes#web-roots-on-production) adds a web root in `current`, such as `apps/docs/public`. Source preparation links the release's `apps/docs/.env` to `<home>/env/apps/docs/.env`, with target `../../../../env/apps/docs/.env`, before the deploy steps run. A deployment's activation checks each such web root and its links, adds a missing `.env` link, and grants Caddy access before it switches `current`. A rollback adds a missing `.env` link before it switches `current`; the deployment that prepared the release granted the access. Only active Routes count. An Instance without such a Route uses only its Instance web root.

A new release without such a directory fails at source preparation. Like any other preparation failure, it keeps the earlier selection and leaves the release directory. A rollback to an older release without the directory fails at activation and keeps the earlier selection.

## Shared paths

Production Instances keep shared directories in the Instance home. Before any `before_activation` step, Orbit links each declared path from the new release to `<home>/<path>`. A first deployment creates the persistent directory and seeds it from the directory in the new release. Later deployments keep every file it holds, add the files of the release copy it lacks, and discard the release copy. These operations run as the Instance user. Deploy steps must preserve these links.

Swamp prepares, activates and prunes releases over official SSH from 209. Git and file changes run as the admitted Instance user through sudo. Deployment leaves the root-owned layout marker that a clone writes as it is, and it never calls an agent action.

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

- When tracked files have uncommitted changes, Orbit refuses with `deployment.checkout_dirty`, and the checkout stays unchanged.
- When the local default branch has commits that the fetched commit does not contain, Orbit refuses with `deployment.branch_diverged` and changes nothing. A checkout would drop those commits.
- When the checkout would overwrite an untracked file, Git refuses it.

**Steps.** The Project's [development deploy steps](#development-deploy-steps) run in list order in the checkout. They report the `before_activation` phase. There is no activation, because the checkout is what the Instance serves.

When every required step passes, Orbit records the commit as the default's `seed_commit`. When a required step fails, the later steps do not run and the deployment fails.

- The checkout stays at the new commit with the files the steps left. Orbit does not roll it back.
- The seed keeps the last commit that deployed. So the automatic deployment tick deploys the commit again after a backoff and reruns the steps, at most three times.

A failed best-effort step (`required: false`) does not stop the deployment; the later steps run.

A step and Orbit's checkout take the lock that [setup and teardown steps](/reference/instance-setup) hold on the checkout: a `flock` on the checkout directory. While a setup or teardown step runs there, the deployment fails with `instance.lifecycle_busy` and the tick retries after a backoff. A step's processes do not inherit the lock, so a process that a step leaves running cannot hold up the next step. Steps must not change tracked files: the next deployment would refuse the checkout as dirty.

Explicit environment synchronization writes the checkout's `.env` files, so the application sees new values at once. A Route's web root serves files from the checkout, and a deployment does not change the Route.

A successful container default create or checkout migration records its completed Route projection with the seed. It needs no separate automatic-policy publication before a manual deployment.

If the default has pending Route publication, the automatic policy admits a normal Route update before deployment. A matching succeeded Route receipt clears that mark through the policy projection. Native dev deployment does not change the Route. An unchanged tick with a completed projection does not take the [projection lock](/reference/routes#coordinate-publication), because a full converge rebuilds the Node's PHP-FPM pools, Caddy, and DNS and blocks every other Route operation while it runs.

### Container source facts

Before admitting a manual or automatic deployment of a development default in a container, Orbit refreshes the Project's source facts with the same capture used by `project:update`: PHP version, source classification and environment template. It reads the requested commit. Without `--commit`, it reads the default branch head once and pins the deployment to the returned SHA.

Before the source read, Orbit checks that any [move into a container](/reference/development-containers#migrate-a-checkout-instance) has settled. The source read runs before the admission write transaction. Under admission ownership, Orbit rechecks idempotency, automatic-deploy eligibility and the source configuration before recording the facts, and checks the move again before admitting the deployment. A changed source configuration refuses with `deployment.source_changed` (409). The container intent, including its PHP paths and Process units, is rendered after the facts are recorded; a later branch change cannot substitute another commit. The fetch still checks that the pinned commit is on the branch's first-parent chain.

A failed read refuses admission with `project.source_unavailable` (502), or the source-access error when credentials cannot be obtained. Invalid or unsupported PHP or framework metadata refuses with its classification code (422), such as `app-dev.php_version_unsupported`. A web-serving Project without PHP refuses with `project.source_defaults_incomplete` (422); a missing static PHP build refuses with `instance.host_unavailable` (409). These refusals start no deployment and leave the stored facts unchanged. Orbit never deploys using stale PHP or framework facts after a capture failure. Environment-template merging and invalid-file handling follow the [existing template rules](/domains/applications#how-the-template-changes).

A successful admission updates the Project-wide facts and environment template at once. Failure or cancellation of that deployment does not restore them. New container intents and later rendering of existing container Process units use the current Project PHP version. Running units change only when an operation applies their new configuration.

The seed changes only after a successful deployment and determines the files a clone copies, not its PHP selection: after a failed or cancelled deploy, a clone can copy the older seed while selecting the newer admitted PHP version. Checkout Instances and production deployments do not refresh Project source facts through deployment.

### Seeds

A new development Instance on the same Node starts from the default: Orbit records the default's checkout as its `seed_path` and the default's `seed_commit`, and creates a linked worktree of the default's repository at that commit. [Setup steps](/reference/instance-setup#run-setup) receive the two values as `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` and copy dependency folders from the checkout. The checkout can change while they copy, because a deployment can run at the same time. A copied folder is a quick start, not a matching set: setup must still run its locked installs. A default's own setup gets no seed.

## Deploy steps

Development deploy steps run at the checkout's repository root, and production deploy steps at the release's repository root, not at a nested application's directory. For root `apps/site/public`, an Artisan step must say `cd apps/site && php artisan migrate --force`. Changing the web root does not change the scope of a repository-owned command.

A deploy step is a named command that runs during a deployment. Each production Instance stores its own steps. A Project stores a separate ordered development deploy list, shared by its development deployments and independent of setup and teardown. Storing a step does not start a deployment.

### Development deploy steps

Use `project:dev-deploy-step:list`, `project:dev-deploy-step:create`, `project:dev-deploy-step:update`, and `project:dev-deploy-step:destroy`, with `--project=ID`. The API collection is `/api/v1/projects/{project}/dev-deploy-steps`; GET lists, POST creates, and PATCH or DELETE on `/{name}` changes or removes one step. MCP exposes the same operations.

Each step has `name`, `command`, `timeout_seconds`, and `required`. Names use lowercase letters, digits, and hyphens, with a letter or digit at each end, and are at most 63 characters. Commands are nonempty UTF-8 strings of at most 16 KiB without NUL bytes. Timeouts are 1–900 seconds, default 300. `required` is a JSON boolean, default `true`; the CLI accepts `--required=true` or `--required=false`. PATCH accepts any of command, timeout, required, or placement; omitted fields stay unchanged.

A new step appends unless `before` or `after` names another step in this Project's development deploy list. The fields are exclusive. Updates without placement keep their position. Names cannot be renamed. Each list permits at most 32 steps and 3,600 seconds of total timeout. Unknown names, duplicate names, invalid fields, unknown placement, self-placement, and limit violations are refused without changing the list. Node access follows the Project's owning Node. Activity records never include commands.

Swamp fetches the branch and checks out the commit over pinned official SSH as the admitted Node account. The Project commands run in list order as that account, one SSH command each. Checkout refuses tracked changes and a local branch with commits that the target lacks. The public result reports only the refusal code. The checkout, the cleanup and each command take system `flock` on the checkout directory. A command refuses with `instance.lifecycle_busy` while a setup or teardown command holds that directory.

Development commands are captured as named vault execution copies and delivered on SSH stdin. A command runs with a clean environment: `HOME`, `USER`, `PATH`, `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`, plus `VP_HOME` under a Vite+ runtime. It does not load the checkout's `.env`; the application reads it. Production commands receive the same rendered values as private, quoted exports on SSH stdin. Bash reads the private program with `-s`, suppresses output before parsing its private body and runs the command with empty stdin. No command file is copied to the Node.

At run start the Gateway mints a repository-scoped Contents read token with the GitHub App and writes it into a 0600 credential file for the fetch. The next step removes the file. A public origin, or one without an App installation, fetches anonymously. GitHub CLI source access is refused for this native path. Command output is discarded so application secrets cannot enter native history.

Required steps must pass before Orbit records the commit as deployed. A failed or timed-out best-effort step (`required: false`) does not fail the deployment, and the files it wrote stay. Use required steps for installs, builds, and migrations, and best-effort steps for cache warm-up.

### Production deploy steps

Production steps belong to an Instance and run before or after activation.

| Field | Contract |
| --- | --- |
| `name` | Unique within the Instance. 1 through 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. |
| `phase` | `before_activation` (the default) or `after_activation`. |
| `command` | Non-empty UTF-8 of at most 16 KiB, without a NUL byte. |
| `timeout_seconds` | 1 through 900. The default is 300. |

A production step runs over official SSH from 209 through sudo as the admitted Instance owner, at the release's repository root, with a cleared environment and Bash in `-eu` mode. The Gateway renders the admitted environment and passes the private command from the vault on SSH stdin. The command receives empty stdin, and no step file is copied to the Node. The step's output stays on the Node: run history, logs and the result keep none, and a failed step names the step instead of an output tail.

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

Before a JavaScript step (`npm-build`, or a command using `vp`, Node, npm/npx, pnpm, Yarn, Bun, Vite, or Corepack), the deploy prepares the [Project environment in the vp store](/reference/tools#project-environments-in-the-vite-store) when the Project declares `vite-plus`. It runs the shared Vite+ steps that a development deploy and `instance:clone` also run. Swamp reads the new release's requirements through sudo and validates them. Swamp installs the pinned runtime in the production account’s private Vite+ cache before the application command.

The shared Node manager supplies its standalone CLI. Swamp publishes only that executable to `/opt/orbit/vite-plus/bin/vp`, with mode `0755` and root ownership. A comparison skips an unchanged copy. It copies no manager credentials or private data. The first CLI invocation can bootstrap that cache; Swamp then installs the exact selected runtime versions. Application commands use the private cache.

It records desired versions before installing missing content and verifies the result before allowing the step to start. It does not run package install scripts as the store owner.

The default JavaScript deploy runs `vp install --frozen-lockfile` followed by `vp run build`, through `vp env exec` with recorded exact Node and package-manager versions as the Instance user. That execution uses the app-owned private cache. A data migration replaces stored legacy default steps containing hard-coded Node and npm paths; runtime execution does not rewrite commands. Customized commands keep their flags, shell operators, and additional build scripts, including SSR builds.

A missing runtime or package manager that cannot be installed fails with `deployment.vp_store_failed`, before the build command runs. Invalid or incompatible Project requirements fail with `deployment.vp_requirements_invalid`. The current production release stays selected. Fix connectivity, store ownership, or Project requirements and retry the deploy; no manual runtime installation is required.

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

These primitives retain the existing operation receipt, history, output, recovery, target exclusion and cooperative cancellation. Swamp owns automatic policy for production and default development checkouts, and executes their deployment Operations. Both policies use the same keyed Gateway admission and receipt contracts. The policy runs on a Swamp worker on Node 209 and calls the Gateway API directly over HTTPS, trusting the Orbit root CA.

### The deploy operation

The Gateway validates the request and the access grant, checks that the Instance can be deployed, and answers `202`. A refusal uses the normal JSON error envelope: `deployment_config.unavailable` (409) for an Instance that is neither a production Instance nor an active development default, and `operation.target_busy` (409) while another operation holds the Instance.

A production deploy holds the Instance, freezes the full intent and records a running deployment row, which [pauses automatic deployment](#pause) at once. It refuses an Instance without stored environment configuration with `env.configuration_missing` before it records anything.

One native run on Node 209 then runs the [chart](/cli/instance/deploy)'s steps over official SSH. Its budget is the deploy step timeouts plus 900 seconds.

| Step | What it does | Error code |
| --- | --- | --- |
| `Write release credential`, `Prepare the release`, `Remove release credential`, `Read the release commit`, `Prepare shared paths`, `Link the release` | Clones the repository into the new release and checks out the frozen commit or branch head. Seeds and links the shared paths and each `.env` | `deployment.source_failed` |
| `Read first-parent chain`, `Check the branch commit` | A pinned commit only: checks that it is on the branch's first-parent chain | `deployment.commit_not_on_branch` |
| `Read the key names`, `Check for foreign keys`, `Create the orbit-env group`, `Write .env`, `Set the group orbit-env` | Refuses keys of `<home>/.env` that Orbit does not manage. The Instance account writes the rendered `.env` from the vault with mode 0640; root then runs `chgrp -h orbit-env` | `env.sync_unconfirmed`, `env.sync_would_drop_keys` |
| `dev-vp-*`, `Read Vite manager home`, `Read Vite manager CLI`, `Publish public Vite CLI`, `Prepare Vite cache` | The shared [Vite+ steps](#vite-store-prerequisite), when a step needs Vite+ | `deployment.vp_requirements_invalid`, `deployment.vp_store_failed` |
| `Run before steps` | Each `before_activation` step in order; a failed step skips the rest | `deployment.step_failed` |
| `Grant Caddy access`, `Activate release`, `Swap current` | Grants Caddy access to each served web root, writes `current.next`, and renames it over `current` in one atomic rename | `deployment.selection_invalid` |
| `Prepare PHP directories`, `Read local PHP tuning`, `Write PHP configuration`, `Write default PHP tuning`, `Test PHP configuration`, `Start PHP runtime`, `Check PHP runtime`, `Refresh PHP cache` | Prepares and verifies the dedicated PHP-FPM service on its first deploy, then reloads it gracefully | `deployment.php_runtime_unconfirmed` |
| `Install Processes`, `Restart Processes` | Installs the captured Process definitions in the `process.create` shape, then reloads or restarts each running Process with `restart_on_deploy` | `process.install_failed`, `process.restart_failed` |
| `Run after steps` | Each `after_activation` step in order, over private SSH stdin | `deployment.step_failed` |
| `Read Schedule group`, `Render Schedules`, `Write Schedule services`, `Install Schedule timers`, `Read Schedule timers`, `Check Schedule timers` | Converges captured Node timers and checks their desired state | `schedule.install_failed` |
| `Find releases in use`, `Clean up releases` | Removes each release outside the kept three, as [Retained releases](#retained-releases) describes; best effort | none: a failed cleanup is a warning |
| `Read current back`, `Read deployed commit`, `Check Process state`, `Read container state`, `Check the deployment` | Checks the selected release, commit and Process state | `deployment.selection_invalid`, `process.restart_failed` |

A development default runs its own steps in the same workflow; [Native development deployment](#native-development-deployment) lists them.

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`. A failed operation names the step in `failed_step` and the deployment's error code in `error_code`, such as `deployment.step_failed`. A failed deploy step is named `before_activation.<step>` or `after_activation.<step>`. A production result also counts the failed cleanups in `warnings`.

### The rollback operation

The Gateway validates the request and the access grant, checks that the Instance is a production Instance that retains the release, and answers `202`. It reads the retained releases from the Instance's [deployment history](#retained-releases), not from the Node. Admission holds the Instance, freezes the full intent for the run, and records a running rollback row, which [pauses automatic deployment](#pause) at once. A refusal uses the normal JSON error envelope and changes nothing:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `rollback.release_not_retained` | 422 | The Instance's deployment history does not retain the release. [`instance:release:list`](/cli/instance/release-list) lists the releases that history retains |
| `deployment_config.unavailable` | 409 | The Instance is not a production Instance |
| `operation.target_busy` | 409 | Another operation holds the Instance |

One native run on Node 209 then runs these steps over official SSH ([chart](/cli/instance/rollback)). Its budget is 900 seconds.

| Step | What it does | Error code |
| --- | --- | --- |
| `Activate release` | Links each served application's `.env` in the release, then writes `current.next` with `@swamp/cfgmgmt/link`, owned by the production account. A link into a removed release fails, and `current.next` is not written | `rollback.release_invalid` |
| `Swap current` | Renames `current.next` over `current` with `mv -fT`, one atomic rename, so `current` always exists and keeps its owner. Skipped when a link failed | `deployment.selection_invalid` |
| `Refresh PHP cache` | Reloads the dedicated PHP-FPM service gracefully, which also empties its OPcache. Only for an Instance that serves PHP | `deployment.php_runtime_unconfirmed` |
| `Restart Processes` | Reloads or restarts each running Process with `restart_on_deploy`, captured at admission | `process.restart_failed` |
| `Read current back` | Reads the live `current` link with `readlink` | `deployment.selection_invalid` |
| `Check the selection` | Checks that the link read back names the release | `deployment.selection_invalid` |

When the operation ends, `result` holds `deployment_id`, `release`, `selected_release`, and `commit`, as for a deploy. `release` is the retained release, and `commit` is the commit its deployment recorded. A failed operation names the step in `failed_step` and its error code in `error_code`. `selected_release` is null when `Activate release` or `Swap current` failed; the Instance then keeps its recorded selection.

## Deploy

A deployment reads the branch and the steps once, when it starts. Then it runs these phases in order.

1. **Source preparation.** Swamp clones the branch into a new release as the admitted production user. Then it prepares the shared paths and the release's links.
2. **Environment sync.** Swamp writes the `.env` the Gateway rendered at admission into `<home>/.env`, as [synchronization](/reference/environment-variables#synchronize) does.
3. **Before activation.** Swamp runs each `before_activation` step in order, from the new release, as the production user, with a non-interactive shell.
4. **Activation.** Swamp grants Caddy access to the new release and replaces `current` atomically with a link to it.
5. **PHP refresh.** Swamp reloads the dedicated FPM service gracefully; its new workers start with an empty OPcache.
6. **Process restart.** Swamp installs captured Process definitions and restarts active Instance Processes that are desired `running` and have `restart_on_deploy: true` (the default). Stopped Processes stay stopped.
7. **After activation.** Swamp runs each `after_activation` step in order.
8. **Cleanup.** Swamp removes old releases, as [Retained releases](#retained-releases) describes. A failed cleanup is a warning in the output, not a failed deployment.

Each Process restart is one native step: `systemctl reload-or-restart` for a systemd Process, `docker restart` for a container. A failure stops the deployment before after-activation steps and cleanup. The new release stays selected.

Deployment environment synchronization only writes files. It does not run the explicit env-sync runtime refresh or restart Processes before activation. Explicit [production env-sync](/reference/environment-variables#synchronize) refreshes PHP and uses the same Process restart action afterward, reporting restarted Process IDs in its Operation result.

Orbit skips PHP work for an Instance that does not serve PHP. The first deploy prepares its dedicated pool and verifies it before the Gateway records the runtime identity. Later deploys reload the verified pool. Node PHP packages remain part of the Node recipe.

To opt out, set `restart_on_deploy: false` in a Process create or update request, or pass `--no-restart-on-deploy` to [`process:create` or `process:update`](/cli/process). This setting applies to production deployments, rollbacks, and a successful explicit production env-sync runtime refresh. For Horizon managed as an Instance Process, keep the default and remove a hand-made `php artisan horizon:terminate` after-activation step with `instance:deploy-step:destroy`. Orbit now restarts that Process before after-activation steps. The normal runtime stop behavior applies.

Orbit runs no migration, Laravel cache clear, dependency install, asset build, or application health check of its own. With no deploy steps, Orbit runs only its runtime operations. The `current` switch is atomic, but the dedicated FPM restart is not zero-downtime: requests can fail briefly while its socket and workers are unavailable, and in-flight requests can be interrupted. Other Instances keep their own PHP services. A confirmed no-op reconciliation does not restart FPM.

Each step has its own timeout. A timeout stops the step's process group and every later step. The whole deployment has a deadline of the step timeouts plus 900 seconds, the [run budget of an operation](/reference/operations#native-run-budgets). A rollback has a deadline of 900 seconds. When a deadline stops the run, the error code is `operation.timed_out`.

## Failures

The failed boundary decides which release stays selected.

| Failed boundary | Selected release |
| --- | --- |
| Source preparation, environment sync, or a `before_activation` step | The earlier release, or none before the first deployment. |
| Activation | The new release when `Swap current` succeeded, else the release selected before the deployment. |
| PHP refresh, a Process restart, or an `after_activation` step | The new release. |

Orbit never undoes the effects of a step, of the environment sync, or of data changes. You decide how to recover.

Rollback and the release list check that the production home records the Project's `repository_url` in its release layout marker and in the origin of the selected release. When that check fails, the Gateway reads the recorded URLs to name the cause. A deployment clones the Project's current `repository_url` into its new release.

| Error code | Cause | Recovery |
| --- | --- | --- |
| `deployment.repository_rebind_required` | The home records another URL for the same repository, for example the SSH form after the Project moved to HTTPS. | Run `orbit project:update <project> --repository=<url>` with the Project's current URL. It [re-binds the home](/reference/projects#production-instances). |
| `deployment.repository_mismatch` | The home records another repository. | None in place. The home belongs to another repository. |
| `deployment.selection_invalid` | Another selection check failed. | Inspect the native selection result and release layout. |


## Roll back

A rollback selects one retained release through `current`. The release and its PHP runtime belong to the Instance, so a rollback changes no pool or unit: a graceful reload of the dedicated FPM service lets running requests finish and starts new workers with an empty OPcache.

After that reload, a rollback reloads or restarts the same active, opted-in Instance Processes as a deployment. A restart failure fails the rollback at `Restart Processes` and leaves the rollback release selected. A rollback fetches nothing, writes no environment, runs no deploy command, and changes no database file.

## Automatic deployments

When the shared producer is enabled and unpaused, Swamp runs automatic-deploy policy every minute at second 30 (`30 * * * * *`). It discovers enabled, active production Instances on Nodes with an active `app-prod` role and active default development checkouts on `app-dev` Nodes. The Gateway retains admission, locks, authorization and history. The PHP automatic-deploy command, alias and schedule are removed. The native end assertion fails when the policy producer records a failure. The Gateway captures the full event receipt from that producer in its existing terminal history read, including events from a failed fire; no reporting step prints it.

Each active policy tick reads the branch head through the Gateway with `git ls-remote` and the Project's [read credential](/reference/instance-setup#credentialed-git). Both policies use the [Gateway source contracts](#swamp-policy-contracts). Development follows the Project default branch. It keeps the GitHub App installation that covers the repository for up to 60 minutes. It keeps the read tokens for up to 50 minutes, encrypted with the application key. Between token refreshes, each eligible Instance costs one `ls-remote` and no GitHub API call in a quiet minute. A failed read drops the cached installation and tokens.

- When the Instance already runs the commit the tick would deploy, the tick starts nothing and writes no history row.
- Otherwise it starts an [`instance.deploy` operation](#the-deploy-operation) pinned to that commit. The [origin label](#history) identifies the policy that admitted it.
- The deployment proves that the branch contains the commit and checks out exactly that commit. It fails with `deployment.commit_not_on_branch` otherwise.
- Pushes that arrive meanwhile wait for the next active tick. That tick reads the newest head, so intermediate commits coalesce.
- While another operation holds the Instance, the tick skips it and reports `busy`. Its own operation that has not ended yet is `deploying`.
- The tick finds the holder with `operation:list`. List rows carry no input, so it reads the holder with `operation:show` to compare its commit.

A head the Gateway cannot read fails the tick for that Instance with `source.head_unavailable`. The other Instances still deploy.

### Automatic development defaults

**Delivered state, 2026-11-11.** The independent development tick (#3331) was reverted. Development auto-deploy stays inside the shared `automatic-production-deploy` producer; whether it runs depends on the policy setting. It accepts checkout defaults and has no container-only gate. A Gateway release does not deploy development defaults. Use [`instance:deploy`](/cli/instance/deploy) on the container default to deploy explicitly and take a new seed after success. A clone starts from that default's last successfully deployed seed. An independent producer limited to container defaults remains planned. The default of a [nested-fleet](/reference/development-containers#refresh-after-a-release) Project is the exception: each Gateway release deploys it to the release commit and refreshes its fleet.

While the shared producer is active, a default deploys its default branch's head. Its deployed commit is its `seed_commit`. A failed commit retries as the [failure rules](#failures-of-automatic-deployments) describe; for a default every failure is retryable. A new commit is eligible on the next active policy tick.

### Production Instances

Swamp decides when a production Instance deploys after you turn automatic deployment on:

```bash
orbit instance:auto-deploy:enable <instance>
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

Without a `merge_check`, the tick deploys every new head. `instance:auto-deploy:enable` accepts that and warns about it. A `merge_check` is read through the [GitHub App](/reference/github-app). A Project that reads through the GitHub CLI, or is not on `github.com`, cannot gate: `instance:auto-deploy:enable` refuses it with `instance.auto_deploy_gate_unreadable` while it has a `merge_check`.

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

A manual deployment or a rollback after `auto_deploy` was last set pauses the Instance, whether it succeeded or not, also while it still runs. A rollback writes its history row at admission, so it pauses the Instance before its run starts. The Instance stays paused until you run `orbit instance:auto-deploy:enable` again; a new commit does not resume it.

Resuming continues from the commit that is live then. The commit a rollback moved away from is blocked. The tick reads it from the deployment history, so it counts also when you resume or deploy by hand before a tick saw the rollback. The tick also keeps it in `auto_deploy_state.blocked`, so it stays blocked after its rows leave the history.

A blocked commit stays blocked. It never deploys automatically again, until a newer commit lands on the branch: that commit deploys, and the blocked one is behind it.

A rollback that came after the tick queued the deployment wins while no worker has started the deployment: the rollback cancels it, as [One operation at a time](#one-operation-at-a-time) describes. Once a worker has started it, the rollback is busy.

A rollback or manual deployment can also end between the tick's read of the history and the start of its deployment. So the deployment checks for a pause again under the Instance lock. A pause found there ends the operation with `deployment.paused` and deploys nothing. A first attempt writes no history row. An attempt that a worker resumes after a lost worker ends its row with `deployment.paused`, which does not count as a failure of the commit.

**Visibility.** Each automatic deployment has its operation and its [history row](#history) with the [recorded origin](#history). `instance:show` shows the last result, its commit, the head, and a pause. When a result other than `up_to_date`, `deploying`, or `gate_pending` starts, the tick writes one Activity entry, `auto_deploy.<result>`, with the command `orbit:auto-deploy`. A [release alert](/reference/gateway-recovery#release-alerts) with the target `instance:<id>` reports a failed automatic deployment once per commit, a commit or a pending head on the first tick after it waited at least 30 minutes, once per commit, and a pause.

**One deploy per Instance.** Development defaults, production Instances, and manual deployments hold the Instance as [One operation per target](/reference/operations#one-operation-per-target) describes. Instances behind one Route deploy one after another, not together.

## Swamp policy contracts

The Gateway exposes policy evidence to Swamp. Swamp never reads GitHub, the database, or a notifier directly. These contracts require Gateway Node access. Policy starts stay on API/MCP; receipt resolution also has an operator CLI command.

| Request | Evidence or effect |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-source/head` | A fresh credentialed `head` of the recorded deployment branch. Works without a GitHub gate. |
| `GET /api/v1/instances/{instance}/deployment-source/commits?base=<sha>` | The current GitHub branch `head` and up to 20 first-parent `commits`, newest first, before `base`. Each has `sha`, `parents`, and `descends_from_deployed`. |
| `GET /api/v1/instances/{instance}/deployment-source/checks?commit=<sha>` | The Project's configured `gate` and exact latest check `runs`, with name, head SHA, App slug, status, and conclusion. Empty runs never prove success. |
| `GET /api/v1/instances/{instance}/deploy/automatic/receipt?request_key=<key>&commit=<sha>` | Exact keyed admission lookup, bound to caller, automatic command, Instance, and commit. Returns `request_key`, `found`, `operation`, and retained `deployment`, when available. Conflicting input returns `operation.idempotency_conflict`. |
| `PUT /api/v1/instances/{instance}/auto-deploy/policy` | Publish the public policy projection with the observed `resumed_after`, `last_deployment_id`, and `history_version`. A changed cursor or history returns `instance.auto_deploy_observation_changed`. |

The policy never posts its events. Each fire of `automatic-production-deploy` prints the events it raised in its terminal `result` step: the notable Activity entries and the alerts for a failed deploy, a pause and a stalled commit. The Gateway reads that result from the run and records each event once, as an Activity entry or a release alert. A fire whose Instance tick failed still prints its result, with the failure's error code: a policy refusal keeps its own code, and any other error is `automatic.tick_failed`. Its `failures` assert then ends the run failed.

The source listing contains at most 100 reachable commits, and the walk returns at most 20 first-parent candidates. `reached_base` says the walk reached `base`, including when the last candidate names it as its first parent. `truncated` says the candidate bound or a missing parent cut the walk short. Reaching the repository root without `base` returns both flags false. Swamp can fail closed on a truncated walk. Each returned candidate carries an exact ancestry comparison with `base`. The source reads accept full 40- or 64-character commit SHAs.

The commits and checks reads require the Project's GitHub App access. They return `instance.auto_deploy_gate_unreadable` for another source provider and `instance.auto_deploy_gate_unavailable` when the upstream read fails. They expose evidence, not a green-commit decision. Swamp applies the policy and checks that the walk's head matches its fresh head. A moved branch does not silently change a selected commit.

Instance list/show and broadcasts expose `auto_deploy.policy_owner`, `auto_deploy.resumed_after`, `auto_deploy.blocked`, and `auto_deploy.history_version`. The cursor is set only by `instance:auto-deploy:enable`. The block list combines durable rollback blocks and retained rolled-away history without waiting for a tick. When automatic deployment is off, the block list is empty and the history version is null; these reads make no policy-history query. Swamp keeps its retry and gate state itself. Deployment list/show expose `kind` (`deploy` or `rollback`); origin is recorded in `triggered_by`, with the authorized automatic caller in `caller_node_id`.

A found automatic receipt is the original operation, including its bound caller in `input.caller_node_id`, status, result, and error. Follow that operation and read fresh deployment history before deciding what happened. A missing receipt means only that no admission is recorded at the read: an earlier request might still be in flight. It never authorizes a retry. A worker-lost outcome or a terminal operation without required effect evidence stays unresolved. Keyed operations are retained. Neither lookup nor outcome resolution starts work.

### Clear an unresolved admission hold

Pause new decisions with `orbit instance:auto-deploy:disable <instance>`. Read the exact receipt using the original Swamp caller, Instance, commit, and request key. Never resend the automatic POST to discover its outcome.

Use `POST /api/v1/instances/{instance}/deploy/automatic/resolve` (MCP `instance-deploy-automatic-resolve`) with `request_key` and `commit`. By default it resolves the authenticated Node's key. A Node with Gateway access may pass `caller_node_id` to resolve for the original caller. The CLI is `orbit instance:deploy:automatic:resolve <instance> <request_key> <commit> --caller-node=<original-node-id>`; omit the flag for your own key. Activity records the acting Node and the original caller. Admission and resolution serialize under the same Instance database lock.

If admission is absent, this writes a retained cancelled operation with `operation.admission_fenced`, zero attempts, no target lock, and no deployment. Its unique request identity fences a delayed admission: every later POST with that key returns the cancelled receipt. Swamp may resolve its own stale keys automatically. No operator action is needed for normal lost-response recovery. Repeating resolution returns the same receipt. A different caller, Instance, or commit conflicts.

If admission already exists, resolution returns its receipt without cancelling or rewriting it. Follow an active operation until it ends. Swamp clears its intent on the zero-attempt fenced receipt and may use a fresh key on its next tick. Other holds clear only from terminal effect evidence; resolution never starts a replacement deploy.

A terminal worker-lost receipt without effect evidence still needs investigation: stop the old host writer, inspect the live release, then reconcile through a manual deploy or rollback before resuming. Do not clear Swamp storage by hand. After reconciliation, resume with `orbit instance:auto-deploy:enable <instance>`; the rollback block remains. A missing receipt alone never permits resume or a new request key.

To switch policy back:

1. Disable `auto_deploy` on the affected Instances.
2. Wait until no automatic operation is running or queued.
3. Revert [orbit#1555](https://github.com/hardimpactdev/orbit/pull/1555) (code-only, no migrations).
4. Re-enable `auto_deploy` (PHP owns again).

### One policy writer during the trial

Swamp decides production and default development automatic deployments. The code-owned writer is `swamp`; Instance list/show exposes it as `auto_deploy.policy_owner`. Swamp discovers eligible Instances with `GET /api/v1/instances?auto_deploy=true` and does nothing when the owner is `gateway`. The filter uses the same eligibility rule as automatic admission: automatic deployment enabled, active Instance, production placement, and an active `app-prod` role. An inactive or absent `app-prod` role refuses admission with `instance.auto_deploy_role_inactive`. The worker checks this rule again before effects. Unfiltered Instance listing still includes ineligible Instances. Automatic admission, publication, and event admission refuse with `instance.auto_deploy_policy_owner` under PHP ownership. The deploy and event workers check ownership again before effects.

 There is no environment flag or runtime switch.

The cutover release removes the PHP production policy loop. The development cutover also removes the PHP development scheduler and retry loop. Swamp discovers active default checkouts on app-dev Nodes through the public Node and Instance lists, without requiring the production auto-deploy flag. Deploy Swamp first: it stays idle until the Gateway reports Swamp ownership. Finish or cancel in-flight PHP work through its current owner before activating the Gateway release; existing `operation.target_busy` holds serialize admissions. Swamp reconstructs policy and retries from the public projection and Gateway history.

 Alert deduplication starts in Swamp: the owner accepts one possible repeat alert at cutover. Orbit exposes no stored alert-key contract.

Publication updates only the public projection in `auto_deploy_state`, preserves Gateway rollback blocks, and broadcasts the Instance DTO used by API, MCP, CLI, and web. The project owner waived the slice 1 shadow phase because Hauzer and Mealou have no users; its 14-day live window starts at this cutover.

Publication uses a short database transaction without the deployment host lock. The history version hashes the stored fields of retained deployment rows, including `caller_node_id`, without events or display names. Adding a deployment or finishing an existing deployment changes it, even when the newest ID stays the same. Renaming or removing a caller Node does not change it. The recorder writes the terminal history and rollback blocks in one transaction, with the Instance row locked and refreshed before changing policy state. It preserves a projection that publication already committed.

It accepts only the cursor and history version Swamp observed, so a later manual action cannot be hidden by a stale publication. Resume admission remains Gateway-owned; Swamp executes deployment effects. Swamp owns selection, retries, gate caches, alert intents, and publication order; it must serialize ticks for each Instance.

Event operations use a separate `instance_policy` target lock. A running deployment does not prevent a stall alert. The delivery step cannot resume after worker loss, because a webhook may already have left the Gateway. Replaying its admission key returns the same operation; it does not deliver twice. Inspect partial notifier receipts and uncertain outcomes instead of creating another event key.

The Gateway derives alert targets and repositories from the Instance. Both policy callers use the same history selectors for the current pause and latest terminal automatic failure after resume, including the required commit for a failure alert. They also use the same notable Activity writer. Callers cannot choose an unrelated notifier target.

## History

The Gateway records one row for each deployment and each rollback. It writes a deployment's row when a worker first starts it, and a rollback's row at admission. It completes the row when the run ends. It keeps the last 50 rows for each Instance.

Each row holds `kind` (`deploy` or `rollback`), `release`, `branch`, `commit`, `started_at`, `finished_at`, `duration_seconds`, `status` (`running`, `succeeded`, or `failed`), `failed_step`, `error_code`, `selected_release`, `triggered_by`, `caller_node_id`, and `events`. `events` holds the phase events of the run. The run history keeps no command output; a failed step is named in `failed_step`. A deployment saves them after each step, so a resumed deployment keeps the events up to the last step that ended. The `cleanup` phase marks release cleanup. A development deployment records `commit` and leaves `release` and `selected_release` null.

The web, CLI, API, and MCP show the stored origin. Deployment and operation reads expose nullable `caller_node_id` and `caller_node_name` separately from the readable `triggered_by` label. The web and CLI show the current caller Node name. If the Node has been removed, they show “Removed Node” with its retained ID.

Swamp deployment rows copy the authorized Gateway peer's Node ID from the operation when the row starts. The ID is retained if the Node is renamed or removed. `caller_node_name` is the current name, or null if there is no caller or the Node has been removed. Manual deployments and the Gateway development runner have no automatic caller ID. Operations also retain the bound ID in `input.caller_node_id`.

| `triggered_by` | Meaning |
| --- | --- |
| `swamp:auto-deploy` | Swamp production or development policy admitted through the automatic endpoint. `caller_node_id` identifies the authorized Gateway peer. Fenced automatic admission receipts use the same origin. |
| `orbit:auto-deploy` | The previous PHP automatic runner's label. Existing rows keep this label, including early Swamp admissions. No labels or caller IDs are backfilled. |
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

Native deployment and rollback own release-layout and selection validation. Their results carry the outcome. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat release, Git, `.env`, web-root or source checks.

## Retained releases

When preparing a candidate, Orbit keeps the selected release's Node and package-manager references, even when its build fails. Retained releases keep those references for rollback too. Release cleanup drops references only for releases it takes out of the retained set; a release kept for running processes keeps its runtimes.

A successful production deployment keeps at most 3 releases in the home: the new selection, the selection before it, and the newest other release. `initial` counts as the oldest, and later releases sort by the creation time in their names. It removes the rest, and the output names each one. So [`instance:rollback`](/cli/instance/rollback) can return to the previous selection and one other release. A rollback removes nothing. A failed deployment removes nothing either; the next successful one does.

The Gateway applies the same rule to the Instance's deployment history to know which releases a home retains: clone selects `initial`, each deployment adds the release it prepared, a successful one keeps those three, and a rollback only moves the selection. Rollback admission refuses any other release. A release that cleanup kept only because a process still used it is not in that set.

The `release-layout` marker is owned by root and records the original layout. Validation authenticates that marker and the selected release without requiring `initial` to remain.

Releases that only stopped Processes used can be removed once they fall outside the retention limit. If a Process opts out, uses a fixed old-release path, or leaves child processes running, it can still use an older release. Cleanup keeps every release that is the working directory, root, or executable of a running process, also beyond the limit. Stop or restart its remaining processes to free it.

A later deployment does not remove such a release, because the deployment history counts only the kept three. Remove it by hand once nothing uses it. When cleanup cannot read where live processes work, it keeps that release and counts a warning.

Cleanup removes only the releases that the deployment history retained before this deployment and that fall outside the kept three. It deletes the release's own files and shared-path links, on one file system. It does not follow those links or delete persistent shared directories in the Instance home. Files in unshared directories go with the release.

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

Automatic policy reads branch heads through the Gateway instead of receiving a GitHub push webhook. A webhook would need a public endpoint and a webhook secret on a Gateway that otherwise answers only WireGuard peers. Swamp uses the source-read contracts for production and default development Instances. Automatic Gateway releases retain their separate source reader. A quiet minute costs one `git ls-remote`, and a pending check costs a few GitHub API reads, well within the App's rate limit. While the shared producer is active, the polling delay is up to one minute; failed-check caches, retry backoff, and the operation queue can add time.

Conditional requests with an ETag were rejected: a quiet minute already makes no GitHub API call, and the cache keeps token requests rare.

### Pending checks need no deadline

The green-commit walk skips a pending commit and walks back to the newest green commit that descends from the deployed one. So no deployment waits for a check to finish, and a deadline for pending checks was rejected. The stall alert after 30 minutes reports a commit that does not deploy.

### Only you resume after a pause

A manual deployment or a rollback is a person's decision about what runs. A merge is not a decision to undo it. So a new commit does not resume automatic deployment; only setting `auto_deploy` again does.

### One native root for every deployment

Development and production deployment use one native workflow on 209. Manual and automatic runs share it. The Instance lock keeps a second deploy or a rollback from starting while one runs. Rollback has its own native workflow; no polled executor remains.

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

## Runtime composition

The production deploy writes `.env`, reloads PHP-FPM, installs Process definitions through the `process.create` child workflow and restarts Processes inside its one native run. The Gateway captures the owned definitions and projects only their reported outcomes. Schedule definitions install through their own Schedule kinds.

## Served web-root preparation

The owning native Route or deployment DAG prepares served web roots on the admitted Instance Node. Swamp validates the selected release and root directories, links the environment and grants Caddy access before publication. A primary-root Route needs no additional root preparation.

PHP runtime preparation, served web roots and Vite+ environments are steps of their parent DAGs. They have no standalone `instance.prepare_*` kinds, Gateway child executors or execution receipts. Instance create and clone use the same native Vite+ installer as deploy: the admitted platform, architecture and public recipe select the manager, local manifests select exact runtime pins, and protected registry settings stay on the worker.

## Swamp setup and teardown steps

`instance.run_steps` runs every admitted Project setup or teardown list through Swamp, including lists requested by Instance lifecycle parents. The Gateway records the Node, Project, checkout, execution account, ordered command digests and complete timeouts. It validates current target and configuration on reads, and projects bounded outcomes. It keeps no PHP step executor, command budget, execution receipt or completion proof for this kind.

A list admitted before the writer switch can be cancelled through the Operation API when its matching target is its only execution state, it has never been claimed or started, and it has no steps or children. Cancellation releases that queued intent without adopting a writer or reporting execution success. A native start, uncertain state or any prior execution keeps the existing proof-aware cancellation behavior.

When setup, teardown or deploy runs on a development Instance, it uses the configured Node account that owns the checkout. Development Instances have no `orbit-app-<ProjectID>` account.

The Gateway records the placement environment and derives the account and directory from the Instance and Node, rather than caller input. Production deploy steps run as `orbit-app-<ProjectID>` in the admitted release. Setup, teardown and production deploy use direct sudo from the Node-user worker. Development deploy uses pinned official SSH from 209. All check directories and command digests, clear the environment, enforce timeouts and clean up child processes. Development and production deploy suppress command output; setup and teardown return bounded redacted output. Setup's `@orbit/ci-caches` command is a fixed workflow-owned action. Teardown refuses that setup-only action. The package sandbox stays separate and has no sudo.

The kind uses the direct writer by default. The per-kind setting cannot select the deleted polled path. The same cutover includes writer selection and old execution/test deletion. After worker readiness is verified, merge first when released code is needed for the canary. Prove the complete Gateway-to-worker path on a real dev or disposable target within minutes of auto-release. Revert the cutover immediately on failure. This setup and teardown path still requires the worker on its admitted Node. Development deploy instead uses the 209 SSH path described below.
## Native development deployment

Default development checkouts use `operation-instance-deploy-direct`. Every Node action of this kind is one official `@swamp/ssh` command from 209, as the admitted Node account with the stored host keys. The shared Vite+ manager setup uses official cfgmgmt resources and bounded command arguments.

The Gateway freezes the Node placement, repository, branch, ordered Project command digests and timeouts. It copies the exact commands and the fetch credential to Operation-scoped vault keys before it starts one native run. Command bytes, target dotenv values, registry settings and GitHub tokens stay out of workflow inputs and history. Development rollback refuses `deployment_config.unavailable` because development checkouts have no retained releases.

The run has six parts:

- **Fetch.** Write the fetch credential, fetch the branch, and remove the credential.
- **Checks.** Plain `git` commands check tracked changes and resolve the commit. They prove the commit is on the branch's first-parent chain and check the local branch.
- **Cleanup plan.** Two `git` commands list the untracked entries and the target's directories. 209 plans the cleanup from them.
- **Checkout.** Remove the planned stale entries, then check out the commit, each under the checkout lock.
- **Vite+.** The shared requirement capture and install, the same steps `instance:clone` runs.
- **Steps.** Each Project command runs in order, then a final check reads the branch and commit.

The Vite+ part runs only when a step needs Vite+. A failed required command skips the later ones. For a best-effort command, the step prints only the command's exit status, so a nonzero status does not fail the deployment.

The shared terminal subscriber reads Swamp's native run data and records the deployment once. The first failed step names the error code, as [Development defaults](#development-defaults) lists. Only a verified native success advances the Instance seed. Development has no result callback, private-material read, token-mint request or Gateway command executor.

A checkout Instance with an unfinished [`instance:migrate`](/cli/instance/migrate) refuses deployment with `instance.lifecycle_busy`. A reserved container row keeps the checkout runtime until the move completes.

A `default` Instance in a container deploys with the variant `container` in the same workflow. Ansible phases start the container, fetch the commit into the Node's mirror and push it in, check it out under the same refusals, take the seed snapshot and check the result. Right after the start, a nested-fleet container gets its nested Gateway's [Club key](/reference/development-containers#the-club-key-of-the-nested-gateway) into its `/run`; the Operation's result says what that start did in `nested_fleet_club_key`. Each Project command runs in the container as `orbit`, from its vault copy. [The default Instance](/reference/development-containers#the-default-instance) has the details.

When a development deploy runs, it leaves Process definitions, Route publication and PHP configuration with their existing native kinds. A pending default Route projection refuses deploy admission.

### Automatic policy

A development start explicitly refused by Swamp or unavailable before sending does not create deployment history. This includes a failed execution-copy vault synchronization with zero attempts and no result, which happens before workflow submission. The next scheduled policy observation releases that failed pending admission and may admit a fresh automatic deployment on a later tick. An uncertain start retains its pending intent until its outcome is known.

Policy checkpoints stay durable before and after keyed admission. When one tick writes a state resource several times, its native output contains only the last handle for that resource name. Earlier checkpoint handles are not duplicate method outputs.

Swamp owns development discovery, branch checks, backoff, retry limits and admission. An `interrupted` Operation is terminal, like a failed Operation: after its deployment evidence is settled, the policy applies the normal failure backoff before admitting the branch head again. The existing automatic workflow keeps its minute cadence. Discovery uses the Gateway's canonical eligibility, including ambiguous or inactive role placement. Each native source action and Project command has its own timeout. The Gateway exposes records and projects the public decision; its PHP automatic-deploy command and scheduler entry are removed.

Swamp settles pending default publication through the normal Route update admission before admitting a newer commit. Its policy projection accepts only a successful Operation for the same authoritative Route.


## Native production deployment

Production admission captures canonical Project Process definitions in the same transaction as the parent Operation. A refused parent leaves no copied definitions. Public input fixes the Project-owned `orbit-app-<ProjectID>` account, its home, the branch and an optional pinned commit. It also fixes the ordered command digests and timeouts, the shared paths and the served web roots. The `.env` plan holds the key names and the sha256 of the rendered file; the dedicated PHP-FPM configuration and the Vite+ setup complete the intent.

A first deploy writes and validates its dedicated PHP-FPM configuration and starts its unit; later deployments reload it gracefully. Existing local PHP tuning remains intact. The Gateway records the runtime identity only after a compliant native service check. The release name follows from the Operation's creation time and id.

When the run starts, the Gateway copies each deploy step, a repository-scoped read token and the rendered `.env` to Operation-scoped vault keys, and passes every Node action as a public argv list or link. It refuses to start when a step's stored command differs from its admitted digest. The Gateway does not execute a command, look up an account remotely or admit a child Operation.

The shared terminal subscriber reads Swamp's native run data and records the deployment once: its release, selection, commit, failed step and error code, the owned `.env` keys, the selected checkout, verified Vite+ pins and the Process outcomes. A failed deployment keeps the selection the native result shows; the Gateway performs no compensating runtime work.
