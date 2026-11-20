---
title: "Projects"
description: "How a Project records one repository, its type, its source access, and the source defaults that Instances inherit, and how create, update, and removal work."
covers:
  - "apps/gateway/app/{Actions,Domain,Infrastructure}/Projects/**"
  - "apps/gateway/app/{Domain/SourceControl/{GitRepositoryIdentity,GitRepositoryOrigin,ProjectRoot,RelativeWebRoot,RepositoryDefaultBranchResolver},Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver}.php"
  - "apps/gateway/app/{Http/{Controllers/Api/ProjectsController.php,Requests/Projects/**},Data/Projects/**}"
  - "apps/gateway/app/Models/{Project,ProjectUpdate,ProjectInspection}.php"
  - "apps/gateway/app/{Domain,Infrastructure}/Instances/*ProductionRepository*.php"
  - "apps/cli/app/Commands/Projects/**"
  - "swamp/extensions/behaviors/{dependency_scanner.ts,_lib/service_detection.ts,_lib/{canonical_json,dependency_inventory,dependency_results,dependency_results_schema,project_schedule}.ts,_lib/maintenance/inspect.ts,operations/project-maintenance/**}"
  - "apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_13_120000_add_maintenance_to_projects,2027_01_13_130000_create_project_inspections_table,2027_01_14_120000_drop_project_node_exclusions_table}.php"
---

# Projects

A Project records one Git repository and the defaults for running it. New Instances inherit its default branch and root. Its type decides what those Instances can do. The API path is `/api/v1/projects`, and the CLI family is [`project`](/cli/project).

## Nested fleet setting

`nested_fleet` is a stored and public boolean that defaults to false. Set it through the Project update API with a JSON boolean. It is for the Orbit Project: its default is a container with an inner fleet, which tracks the release commit on `main`. A copy of it publishes a draft pull request to the Project's repository, and nothing merges it by itself ([no merge](/reference/tasks#no-merge)). Other Projects keep it off.

Every Instance of a Project with the flag on is a container in the nested Incus project, made from the [nested-fleet image](/reference/development-containers#the-nested-fleet-image). One placement rule in the Gateway decides this for every path that writes an Instance or its runtime. Only development create, its retry and Conn create make such an Instance: the Project's default, or a clone of it. They refuse every other result before any record:

- the Node hosts no nested fleets: `instance.nested_fleet_host_unavailable` (409);
- an existing checkout retry would not be a container: `instance.nested_fleet_container_required` (409);
- a new non-default has a checkout default: `instance.container_required` (409); migrate the default first.

A Node hosts nested fleets in two cases. Its [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) is on. Or it is a [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node), and its last `ci-runner` converge prepared nested hosting. A CI-only Node takes only a proof copy. It refuses every other create with `instance.node_ci_only` first.

Every other path refuses such a Project with `operation.kind_unavailable` (503), because it makes no such container. These paths are clone, register and migrate, and task work that needs a new task VM workspace: provision and turn, also after a wait. A `proof:run` of such a Project runs in [copies](/cli/proof/run#the-run). Each refusal comes before any record: no Instance, runtime, task place, Node identity, capacity wait or Operation. An Operation that already waited when the flag was turned on ends `failed` with the same code. A Project with the flag off is not affected.

The same placement rule also says where a new Instance lives:

| Project | Node | Result |
| --- | --- | --- |
| Flag off | app-dev | A new container in the Incus project `orbit-instances`; existing checkouts keep working |
| Flag on | The Node hosts no nested fleets: switch off, and no CI-only Node with nested hosting | Refused with `instance.nested_fleet_host_unavailable` (409) |
| Flag on | Hosts nested fleets, but an existing retry would use a host checkout | Refused with `instance.nested_fleet_container_required` (409) |
| Flag on | Hosts nested fleets and a container result | A container in the Incus project `orbit-instances-nested`; CI-only Nodes take only proof copies |

Each writer calls the rule's entry for its kind. Development create, Conn create and the runtime reservation can make a container. Register, migration and the production clone read or make a host checkout. Task work and `proof:run` make a task workspace.

The Instance runtime records the chosen Incus project once, when Orbit reserves it. A clone takes the project that its default's runtime records. Every later step reads that record and never derives the project from the flag again.

Change it alone: a request that sends `nested_fleet` with any other Project setting is refused with `project.nested_fleet_update_separate` (422) before any change. Turning it on requires the Project ID in the operator-set [trial allow list](/reference/gateway-environment#keys-in-the-example-file); otherwise Orbit refuses with `project.nested_fleet_not_allowed` (409). The list defaults to empty. A malformed list refuses with `project.nested_fleet_projects_invalid` (500). A changed value refuses with `project.nested_fleet_in_use` (409) while the Project has any Instance. Sending the current value is allowed, and turning it off does not require allow-list membership. An omitted member preserves its value.

## Container server

`container_server` chooses the server that runs in the Project's dev containers, its clones that are not task copies and its checkout Instances moved into containers: `conn`, the Conn Server and the default, or `t3`, the T3 server. A task copy always runs the Conn Server, whatever this field says.

Both serve the same client contract and use the same credentials; only the program differs. A container's runtime records the choice when Orbit reserves it, so a change reaches only containers made after it. Existing containers keep their server, because the two servers store threads and pairings differently. To move a container to the other server, destroy it and create it again. [Container server](/reference/development-containers#container-server) describes the program, the unit and the record.

A choice is refused with `project.container_server_unavailable` (409) while the recipe's agent folder does not pin its program. A refused update changes nothing.

## Project Documents

[Project Documents](/reference/project-documents), the `documents` extension, are a native folder tree of notes and versioned attachments owned by the Project, independent of its Instances and Git branches. Orbit keeps their metadata in the Gateway database and their bodies in a dedicated private UpCloud bucket. The shared contract covers editing, upload/download, archive, permanent removal, and recovery across API, CLI, SDK, MCP, and web.

A Project's own agent instructions, an `AGENTS.md` and skills, live in the reserved `agent/` folder of its Documents. Each new clone of the container default gets them at its create, and `project:show` reports them as `agent_instructions` ([Agent instructions](/reference/development-containers#agent-instructions)). The plug-in point `ProjectAgentInstructionSource` lets the `documents` extension answer for the Project.

## Fields

A Project stores these fields. API responses, the SDK, and CLI JSON use the same names.

| Field | Meaning |
| --- | --- |
| `slug` | Unique name, at most 63 characters. It names the directory of each new checkout and the generated domains. |
| `name` | Display name. It defaults to the slug. |
| `code` | Unique code of three uppercase letters. See [Project codes](#project-codes). |
| `type` | `monorepo`, `laravel-app`, `symfony-app`, `laravel-package`, or `node-package`. See [Project types](#project-types). |
| `repository_url` | HTTPS or SSH Git URL that Orbit uses to fetch. |
| `source_access` | `github_app` or `gh_cli`. How Orbit reads a private `github.com` repository. See [Source access](#source-access). |
| `default_branch` | Branch of the `default` Instance and the base for new branches. |
| `root` | Repository-relative web root that Instances inherit, such as `public` or `apps/site/public`. It is not the checkout path or the Laravel application directory. |
| `container_server` | `conn` (default) or `t3`: the server that runs in the Project's new dev containers. Task copies always run `conn`. See [Container server](#container-server). |
| `nested_fleet` | Boolean, default false. It is for the Orbit Project: its Instances are containers with an inner fleet, which only `instance:create` makes. See [Nested fleet setting](#nested-fleet-setting). |
| `task_check` | Optional command that task baselines and handoffs run. It defaults to null for every type. See [Project check](/reference/tasks#project-check). |
| `default_auto_deploy` | Boolean, default true. Whether each new commit of the default branch deploys the Project's container default by itself. See [Automatic development defaults](/reference/deployments#automatic-development-defaults). |
| `task_workspace_routed` | Boolean, default true. Whether newly created task workspaces get a Route. See [Task workspace routing](#task-workspace-routing). |
| `review_and_merge` | Boolean, default false. Whether Orbit reviews every push of the Project's tasks, reviews incoming pull requests, and merges reviewed green heads. See [Review and merge](/reference/tasks#review-and-merge). |
| `merge_check` | The check run that must pass on each [handoff commit](/reference/tasks#handoff-ci) and on a head before Orbit merges it, such as `Required checks`. Null by default; the flow needs it. |
| `task_implementer_provider`, `task_implementer_model`, `task_implementer_effort` | The default implementer agent of the Project's [task copies](/reference/tasks#copy-turn-agents): `claude`, `codex` or `antigravity`. A task that names its own value keeps it. Null takes the Gateway's |
| `task_reviewer_provider`, `task_reviewer_model`, `task_reviewer_effort` | The same for the reviewer |
| `task_pr_mode` | The [mode](/reference/tasks#task-group-modes) of the Project's task copy groups that set none: `review_before_pr` or `pr_when_done`. Null takes the Gateway's `ORBIT_TASKS_PR_MODE` |

A parent task started with `task:start <id>` receives a workspace from the newest default-branch commit that passed the `merge_check`; see [`task:start`](/cli/task/start).

In a review-and-merge Project, the CI workflow must run the `merge_check` on pushes to `task-*-handoff` branches too, because each [handoff commit](/reference/tasks#handoff-ci) is tested on its push. Other Projects run no handoff CI.

## Application directory

For a Laravel Instance, the application directory is the effective web root without its trailing `/public` segment. The effective root is the Instance's override when present, otherwise the Project's root. `public` means the checkout root in development, or the release root in production. `apps/site/public` means `apps/site` inside that checkout or release. One shared helper derives the directory for Laravel source inspection and runtime consumers; Orbit does not store a second directory setting.

The application directory contains `composer.json`, `artisan`, development [environment files](/reference/environment-variables#where-the-file-lives), and Laravel [logs](/reference/instance-logs#know-which-file-the-gateway-reads). PHP-FPM, default systemd Instance Processes, and Instance Schedules use it as their application working directory. Production resolves it through `current`. Setup, teardown, deploy steps, and task-check commands still run from the repository root; a nested Artisan step must change directory explicitly. [Setup and teardown](/reference/instance-setup#run-setup) and [task checks](/reference/tasks#project-check) export `VP_HOME` to the Node's resolved Vite+ store, including for project-local `vp`.

Registration never infers a nested root from source files. Configure the Project's root, or send an explicit Instance root override. An Instance can serve more directories of its checkout or release through [Routes with a web root](/reference/routes#serve-several-web-roots). Each one derives its application directory with the same rule and gets its own PHP-FPM pool and `APP_URL`.

The Project's environment template comes from the application directory's `.env.example`. Development create preserves captured live whole-value references for the application's dotenv; see [template rendering](/reference/environment-variables#parent-environment-steps).

## Setup and teardown steps

A Project owns ordered [setup and teardown lists](/reference/instance-setup) for its development Instances. Each named command runs on the Instance's Node from the repository root. A setup step can also name a [Gateway-owned operation](/reference/instance-setup#gateway-owned-setup-steps), such as `@orbit/ci-caches`, which the Gateway renders before the step runs. Production Instances run neither list. Task workspaces clone from the Gateway mirror and run the Project's setup list on each creation. The [private package caches](/reference/compute-drivers#clone-and-set-up-every-workspace) and CI cache ref speed those steps; there is no per-Project template setting or build.

Declaring `@orbit/ci-caches` also lets source setup transfer the selected SHA's cache ref from the Gateway mirror. The operation runs the checkout's cache importer. It carries no artifact payload or GitHub credential. Put it after dependency installation. [CI main caches](/reference/instance-setup#import-ci-main-caches) describes exact SHA matching and the cold fallback; missing cache refs do not prevent source setup.

When a command is missing or not executable on that Node (exit 127 or 126), Orbit returns `instance.setup_step_unavailable` or `instance.teardown_step_unavailable` with the step name and `outcome: missing`. The message names the step, Node, and exit code and says the command was not found or is not executable. Other command failures still return `instance.setup_step_failed` or `instance.teardown_step_failed`. See [Instance setup and teardown](/reference/instance-setup#failure-codes) for retry and removal behavior.

## Development deploy steps

A Project owns an ordered [development deploy list](/reference/deployments#development-deploy-steps), separate from setup and teardown and from production's per-Instance deploy steps. Use [`project:dev-deploy-step`](/cli/project/dev-deploy-step-list) to list, create, update, or remove a step. Each step stores a name, command, timeout, a `required` boolean, which defaults to true, and optional `inputs`, the paths that let a container deployment [skip an unchanged step](/reference/deployments#step-inputs). These operations change configuration only; they never start a deployment.

## Project types

The type belongs to the Project, so every Instance of one repository behaves the same way. The Orbit monorepo has repository identity `github.com/hardimpactdev/orbit`.

| Type | Route | PHP-FPM | Root `.` allowed | Default `task_check` |
| --- | --- | --- | --- | --- |
| `laravel-app` | Exactly one without a web root per active Instance | Yes | No | none |
| `symfony-app` | Exactly one without a web root per active Instance | Yes | No | none |
| `monorepo` | Only an explicit Route | Only with a Route to a Laravel source | No | none |
| `laravel-package` | Only an explicit Route | No | Yes | none |
| `node-package` | Only an explicit Route | No | Yes | none |

`.` means the repository root. A Route cannot target an Instance whose root is `.`. Set a relative web root first. A `laravel-package` Project does not need an `artisan` file.

A `monorepo` gets its PHP version from the root `composer.json` using the same supported-version selection as other PHP Projects. It stays non-Laravel and needs no framework entry point. A repository without a root `composer.json` gets no PHP version. Updating `source_access`, even to its current value, refreshes these source facts. A development default in a container also refreshes them when it deploys; [Container source facts](/reference/deployments#container-source-facts) defines the commit and failure rules.

A `symfony-app` serves like a `laravel-app`: one Route, a PHP-FPM pool, and the PHP version from `composer.json`. Orbit runs no Laravel step for it. It writes no `APP_URL` and patches no Laravel configuration cache. See [Symfony applications](/domains/applications#symfony-applications).

## Create a Project

Create a Project before you create or register its first Instance.

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git
orbit project:create acme laravel-app git@github.com:acme/site.git --default-branch=stable --root=web/public
orbit project:create leden laravel-app git@github.com:acme/leden.git --source-access=gh_cli
```

The CLI root defaults to `.` for package types and `public` for other types. The API and SDK require `root`. Without `--default-branch`, the Gateway reads the remote default branch once and stores it. A later change on the remote does not update the Project. An explicit branch must exist on the remote. The Gateway reads the remote with the Project's [source access](#source-access).

A Project's development `default` [deploys in its checkout](/reference/deployments#development-defaults). Its [setup steps](/reference/instance-setup#run-setup) can use that checkout as a dependency seed for other development Instances on the same Node.

Creation is idempotent. A retry with the same values returns the existing Project. A retry that omits the default branch does not read the remote again. A retry with any different value, including another URL for the same repository, returns `project.identity_conflict` and changes nothing.

## Source access

`source_access` decides how Orbit reads a private `github.com` repository. It applies to every read: default-branch checks on create and update, branch resolution on `instance:create`, and every clone and fetch on a Node.

| Value | Orbit reads with |
| --- | --- |
| `github_app` | The Gateway's [GitHub App](/reference/github-app#how-orbit-reads-a-repository), when an installation covers the repository. Otherwise without a credential. This is the default. |
| `gh_cli` | The [GitHub CLI login](/reference/github-app#read-through-the-github-cli) of the Gateway's `orbit` user. Only for `github.com` repository URLs. |

A public repository and a repository on another host work with `github_app`. A `gh_cli` Project cannot start [tasks](/reference/tasks), because tasks publish through the App.

## Repository identity

The Gateway derives a repository identity from the host and path of the URL. Equivalent SSH and HTTPS URLs, with or without `.git`, have the same identity. A second Project for the same repository returns `project.repository_identity_conflict`. Registration uses this identity to find the Project of a checkout.

## Registration needs a Project

[`instance:register`](/domains/applications#register-an-existing-checkout) adopts a checkout only for an existing Project. It finds the Project by repository identity, or uses `--project`. When no Project owns the repository, it fails with `instance.project_missing` and changes nothing. Create the Project with `project:create` first. Registration inherits its root unless an explicit Instance override is sent; it does not search the checkout for nested apps.

SDK Project responses and the `project:list` and `project:show` commands expose the stored type, repository, source access, default branch, root, task check, `task_workspace_routed`, `review_and_merge`, `merge_check`, and the six task agent defaults. The task check is an ordinary setting, like setup steps, so activity records it as sent. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` compatibility name.

## Retry creation safely

Repeating `project:create` with the same name, slug, type, repository access URL, source access, default branch, root, any sent task check, and any sent `task_workspace_routed` value returns the existing Project. An omitted source access means `github_app`. Omitting `task_workspace_routed` keeps the stored value. A retry does not look up an omitted branch again.

A retry that changes any creation value fails with `project.identity_conflict` and does not mutate the Project. A different repository access URL is a changed value even when it has the same canonical repository identity, so creation never switches the stored URL.

## Project codes

Each Project has a unique code of three uppercase letters. The Gateway derives it from the slug unless `POST /api/v1/projects` sends `code`. The code stays the same when the slug changes. Task cards use it as a label.

Change the code in the web app, or send `PATCH /api/v1/projects/{project}` with only `code`. A code sent with other fields returns `project.code_update_separate`. A code that is not three uppercase letters returns `project.invalid_code`. A code in use returns `project.code_conflict`. When every three-letter code is taken, creation returns `project.codes_exhausted`.

## Update a Project

Use `project:update` to change an existing Project's display name, type, slug, repository access URL, source access, default branch, web root, task check, or task workspace routing. It also changes the automatic deploy of its container default, review and merge, and the task agent defaults. The Gateway API accepts `PATCH /api/v1/projects/{project}` with those same fields, including `task_workspace_routed` and `default_auto_deploy`. The PHP SDK sends `UpdateProjectRequest` to that path. Omitted fields stay unchanged; send `task_check: null` to clear the task check. The CLI and the MCP `project-update` tool accept the same fields. The [Update lifecycle](#update-lifecycle) defines source reconciliation. The API, SDK, CLI, activity, Doctor, and validation contracts expose no `main_branch` or `--main-branch` name.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

`project:update` and `PATCH /api/v1/projects/{project}` change the fields you send and leave the rest. Creation never updates a Project.

| Field | Effect |
| --- | --- |
| `name` and `--name` | Applies at once and touches no checkout, Route, or runtime. At most 255 characters. The slug, domains, and code stay. |
| `type` and `--type` | Applies at once. A `laravel-app` needs a Route; changing away keeps existing Routes. |
| `slug` and `--slug` | Projects every Instance before publication, with no partial projection. Checkout paths, production users, and homes stay unchanged. Generated Routes use the new slug; explicit domains do not. |
| `repository_url` and `--repository` | Runs `git remote set-url origin` in each development checkout and re-binds each production home. Equivalent HTTPS and SSH URLs share an identity. See [Repository changes](#repository-changes). |
| `source_access` and `--source-access` | Applies at once and touches no checkout. See [Change source access](#change-source-access). |
| `default_branch` and `--default-branch` | Must exist on the remote. Switches every development `default` Instance without a `branch_override`. Explicit overrides stay unchanged. |
| `root` and `--root` | Changes the effective root of every Instance without its own root. Orbit reprojects the runtime of each such Instance that has a Route. |
| `task_check` and `--task-check` | Sets the command that task baselines and handoffs run. Send null or `--clear-task-check` to run no check. |
| `task_workspace_routed` and `--task-workspace-routed=true\|false` | Sets routing for future task workspaces. Existing workspaces keep their recorded mode and Routes. |
| `default_auto_deploy` and `--default-auto-deploy=true\|false` | Applies from the next minute's check. See [Automatic development defaults](/reference/deployments#automatic-development-defaults). |
| `merge_check` and `--merge-check=NAME` | Names the check that must pass before Orbit merges, and on each handoff commit. Send null or `--clear-merge-check` to clear it. |
| `task_implementer_*` and `--task-implementer-*` | Set the implementer defaults of the Project's [copy turn agents](/reference/tasks#copy-turn-agents). Send null or `--clear-task-implementer-agent` to clear them. |
| `task_reviewer_*` and `--task-reviewer-*` | The same for the reviewer. |
| `task_pr_mode` and `--task-pr-mode` | Set the [mode](/reference/tasks#task-group-modes) of the Project's task copy groups that set none. Send null or `--clear-task-pr-mode` to clear it. Work done before the change waits for a release. |
| `container_server` and `--container-server=t3\|conn` | Chooses the server of the Project's dev containers that Orbit reserves after the change. Existing containers keep theirs; task copies always run `conn`. See [Container server](#container-server). |

A type change must keep a valid root. When the stored root is `.` and the new type does not allow it, validation fails on `root`. Send a web root with the type change. A type or root change that leaves a Route target with root `.` returns `route.target_web_root_unsupported`.

A slug change does not move a development checkout. Orbit creates it at `APPS_ROOT/SLUG/NAME` and stores that path. Deployment, source resolution and removal read the checkout's directory from the stored path, not from the current slug. So a checkout created under the old slug keeps working after the change.

### Change source access

The Gateway first resolves the remote default branch with the new `source_access` value. When that read fails, nothing changes. A `default_branch` or `repository_url` sent in the same request is checked with the new value.

### Task workspace routing

`POST /api/v1/projects` and `PATCH /api/v1/projects/{project}` accept `task_workspace_routed` as a JSON boolean. Null, strings, and numbers fail with HTTP 422 `validation.failed`, with details for that field. Creation defaults to true; an omitted update leaves it unchanged. An explicit value participates in creation's identity check; omitting it on an identical retry preserves the existing value. API list and show responses, the SDK, CLI JSON, and MCP expose the stored boolean. CLI human detail output labels it `Task workspace routed`.

The create and update commands accept `--task-workspace-routed=true` or `--task-workspace-routed=false`. An invalid CLI value returns `project.task_workspace_routed_invalid` before a request. The setting controls task provisioning only. It does not change ordinary Instances or bypass root, Route, and Project-type validation. A settings-only update does not reconcile existing sources or Routes.

Each task workspace keeps its recorded routing mode after a settings change. Renaming a Project does not change the setting, and the engine never consults the slug.

### Review and merge

`POST /api/v1/projects/{project}/review-and-merge/enable` and `/disable` switch the flow; it applies at the next tick, to open tasks too. `PATCH /api/v1/projects/{project}` accepts `merge_check` as a string of at most 255 characters, or null, and refuses `review_and_merge`. The flow is on only while `review_and_merge` is true, `merge_check` is set, and `source_access` is `github_app`. Enabling without the check or the App, or an update that would leave an enabled Project without one of these, fails with HTTP 422 `validation.failed` on `merge_check` or `review_and_merge`, and changes nothing. CLI human detail output labels the fields `Review and merge` and `Merge check`. An invalid CLI value returns `project.review_and_merge_invalid`, `project.merge_check_conflict`, or `project.merge_check_invalid` before a request.

```bash
orbit project:update 78 --merge-check="Required checks"
orbit project:review-and-merge:enable 78
```

Enabling review and merge requires readable branch rules that do not require an up-to-date branch. Orbit reads rulesets with `Metadata: read` and classic branch protection with `Administration: read`. A 404 for classic protection means none is set. A strict rule returns `project.strict_base`; an unreadable policy returns `project.branch_policy_unavailable`. Both refuse to enable. Projects already enabled are not read again.

[Tasks: Review and merge](/reference/tasks#review-and-merge) describes what the switch changes.

The [hosted handoff CI prerequisite](/reference/tasks#check-an-external-group) follows this same policy through `Project::handoffCheckName()`, not a new caller option. When CI is required, the check operation pins its check name and handoff branch before push. A later Project edit does not reinterpret its candidate evidence. This prerequisite does not enable external review-and-merge admission: external create and topology append retain `tasks.orchestration_unsupported`. No live Project policy change is needed to test it.

### Update lifecycle

The Gateway applies `slug`, `repository_url`, `default_branch`, and `root` as one recorded operation:

| Status | Work |
| --- | --- |
| `reserved` | Records the request and the previous values. |
| `preflighted` | Checks every affected checkout, worktree, production home, and generated domain. |
| `prepared` | Switches branches, changes origins, re-binds production homes, and creates replacement Routes. The old values stay in effect. |
| `publishing` | Replaces generated Routes and updates Instance URLs, environments, and runtimes, then publishes the new Project values. |
| `cleaning_up` | Checks that no production Instance changed. |
| `complete` | Done. |

An update is published once a new value is visible: a replacement Route has cut over, or the Project record holds a new value. A failure before that rolls back, also during `publishing`. Orbit withdraws each replacement Route that never activated, renders each environment it synchronized against the old Route again, and restores origins, production homes, branches, and the Project record.

The update then ends in `rolled_back`, and other updates can run. A retry after a fix starts again. A rollback that fails stays `rolling_back`, and an identical retry continues it.

Orbit records each `origin` change before it runs, and each default branch switch when it ends. So a rollback restores every checkout that changed, or started to change, before a later one failed. A restore does not write an `origin` that never changed. A failure after publication stays in place, and an identical retry continues forward.

A different update while one is published but incomplete returns `project.update_in_progress`. An update holds the locks of every Instance of the Project while it runs, so an unpublished incomplete update that another request finds is not running: an update that stopped before this rule, or a rollback that failed. Orbit rolls it back first and then runs the new request. When that rollback fails, the request returns the rollback's error and the old update stays `rolling_back`. A Project without Instances has no Instance lock. Its incomplete update requires an identical retry; a different request returns `project.update_in_progress` without rolling it back.

Slug rollback withdraws only the recorded replacement or a fresh replacement for the same proposed domain. It leaves an unrelated domain change in place.

A slug change synchronizes the stored environment of each development Instance that has one, against the replacement Route. An Instance without stored configuration has nothing to synchronize. Orbit skips that step and records `environment: skipped` with the reason `env.configuration_missing` in the update evidence; Route convergence still writes the new `APP_URL` into the `.env` of the [application directory](#application-directory). See [Project update boundary](/reference/environment-variables#project-update-boundary).

When updating source, the Gateway calls `project.update-source`. The agent passes `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false` to Git during preflight, origin changes, fetches, branch changes, and rollback. The agent does not run checkout hooks or a custom filesystem monitor for those operations. The overrides do not change the stored Git configuration.

Branch switch, rollback, and origin changes run as the Node's managed user, the owner of the checkout. Branch switch and rollback checkout commands write the work tree, so they run without a credential environment. A switch checks out with `--no-track`, and then records the branch's upstream. Preflight fetches use the Project repository URL and never read the checkout's configuration; see [credentialed Git](/reference/instance-setup#credentialed-git).

Use the owning native Operation result to inspect a failed projection.

### Repository changes

A repository update recomputes the stored repository identity from `repository_url`, even when the URL is unchanged. Send the current URL through `project:update` or MCP `project-update` to repair a stale identity. The repair runs the repository preflight and checks that no other Project owns the derived identity before publication. A refused preflight leaves the stored identity unchanged. An update that omits the repository URL does not repair identity drift.

A repository change touches only `origin`. Local branches, the checked-out commit, and the recorded starting commit stay the same. Orbit never pushes. Linked worktrees share the checkout's repository and need no change. Orbit finds that repository in the path recorded at registration, or, for a worktree that Orbit created from a seed checkout, in that seed checkout.

An Instance in a container keeps no `origin`. Its workspace is filled from the Node's mirror, and each create, clone and deploy fetches into that mirror from the Project's `repository_url`. So a repository change runs no checkout step for it; the next run fetches from the new URL. Instead, the Gateway fetches each container's seed commit from the proposed repository with the Project's source access. When that fails, the update returns `project.repository_preflight_failed`, names the commit and the Instance, and nothing changes.

A task workspace on a task VM gets its new `origin` too. Its preflight checks the checkout but does not read the proposed repository there, because a task VM gets no token ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)). The Gateway checks the default branch in the proposed repository once instead.

Every origin check reads the `remote.origin.url` stored in the checkout. It ignores `insteadOf` rewrites on the Node.

#### Production Instances

A production home records the repository URL in three places: the release layout marker and the initial clone marker under `/var/lib/orbit/app-instance-sources/<instance>/`, and `remote.origin.url` of each retained release. [Deployment and rollback](/reference/deployments) compare these values byte for byte with the Project's `repository_url`.

A repository change re-binds every production home on the release layout to the new URL. Preflight reads each home first. Every recorded URL must name the same repository as the new URL, in SSH or HTTPS form, with or without `.git`. Another repository fails with `project.production_repository_mismatch`, and nothing changes. Prepare then rewrites each value that differs, the releases first and the release layout marker last. Each rewrite is a compare-and-swap, so a retry continues safely. A partial release without an origin stays as it is. Rollback writes the recorded URLs back.

A re-bind changes only those URLs. It never changes production source, the selected release, the deployment branch, or the recorded commit, and it never starts a deployment.

A repository request also re-binds when the URL is already the Project's URL. Use it to repair a home that an update before this rule left on the old URL. Deployment names that repair with `deployment.repository_rebind_required`.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git
```

## Maintenance

Three Swamp workflows on Node 209 keep a Project's dependencies current. [`project:maintenance:inspect`](/cli/project/maintenance-inspect) (kind `project.detect`) reads the manifests and lockfiles of the default branch through the GitHub App without a clone and compares them with the registries, `project.routine` selects this week's routine group, and `project.observe` reads the pull requests that maintenance groups watch.

`project.observe` then refreshes the Project's [dependency findings index](/reference/instance-dependency-contracts). If Swamp [data retention](/reference/operations#swamp-datastore-watch) has deleted a report that the index points to (after 7 days), it leaves the index as it is and does not fail.

The Gateway admits each maintenance step under its own key, such as `maintenance:<group>:<operation>:<kind>`, `maintenance-finish:<group>:<publish operation>` or `native-maintenance:<key>`, with `OperationIdempotency::under`. A repeated pass replays the recorded step and starts nothing.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

The Swamp workflows take the Swamp Node from the intent the Gateway sends (`control_node_id`), and their schemas accept any Node id, because the Gateway finds the Swamp Node by its `swamp` role and not by a fixed id.

The hourly `maintenance-projects` Schedule only ticks: its run lists the Projects that the Swamp source configures, with the request keys of that fire, and the Gateway captures it from the shared terminal history read. The Gateway then selects, from its own tables, which request each opted-in Project needs. A due weekly routine comes first, then an observation while a group that has not ended waits on a pull request or on capacity. Otherwise the Gateway inspects the Project, so an opted-in Project is inspected every hour. The schedule carries no Project or task group data.

The CLI, or the MCP tools `project-maintenance-inspect` and `project-maintenance-routine`, start one directly.

Each run reads only its admitted intent, stamped with its UTC admission time, and GitHub; the Gateway admits any task group from the run's result. Their steps carry the names in [flow charts](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md), so task progress shows names such as `Read the manifest SHAs`. A routine run reads no Swamp history: the Gateway admits one maintenance task per Project at a time and reuses the group whose brief carries the week's key, so a retried routine never opens a second group.

A maintenance task is a [task with no subtask](/reference/tasks#a-task-with-no-subtask): Swamp sends its deliverables on the task itself, and the Gateway moves it to `todo` and starts its copy. The move follows the rule of [a task that leaves backlog](/reference/tasks#tasks-and-subtasks), also for a task the run reuses. A task with one subtask, or with no work, stays in `backlog` and is not admitted. It asks for assistance with the reason `Maintenance task refused in backlog: <error code>. <error message>`, which says how to fix the task. Nothing starts. A later run that refuses the task again replaces that reason with its own error. It leaves any other assistance request as it is.

To recover, fix the task in `backlog`, then rerun the routine in the same week with `project-maintenance-routine`. That run has the same weekly key, so it reuses the task, starts it and clears the refusal, and only that request. The hourly Schedule does not retry: it has recorded the week's routine. The next week's routine has another key and counts the old task as unfinished work.

The Gateway keeps each Project's last inspection, so the next one reads again only the sources whose blob SHAs changed and asks the registries at most once a day, from a per-package cache shared across Projects.

Maintenance is opt-in per Project and off by default. [`project:maintenance:enable`](/cli/project/maintenance-enable) turns it on for a Project that reads through the GitHub App and has a default branch and a task check; [`project:maintenance:disable`](/cli/project/maintenance-disable) turns it off. Only the default branch is inspected. The Schedule skips a Project with maintenance off, and a direct request for it fails with `project.maintenance_disabled` (409). Project responses carry the setting as `maintenance`. Disabling also prevents an earlier observation from changing task groups.

A Project with maintenance on also needs an entry in `swamp/extensions/behaviors/operations/project-maintenance/projects.json`: its Composer and JavaScript paths, release-age policy and exceptions. A run for a Project without one fails with `project.maintenance_not_configured`. Orbit's own policy covers `apps/gateway`.

### Inspection

An inspection reads GitHub with `curl` on Node 209, with a GitHub App token that may only read contents. The Gateway stores the token's whole `Authorization` header as the run's vault copy, and the step passes it to `curl` on standard input. The run lists packages by parsing the lockfiles it read; no package manager runs.

## Remove a Project

`project:destroy` removes a Project that has no Instances and no Routes. It deletes the Project's process and Schedule definitions, setup and teardown steps, and update records.

A Project has no per-Node exclusions. The development Node exclusion feature was removed on 2026-11-03, and its table was dropped. `instance:create` names its Node, so nothing places a Project on an `app-dev` Node by itself.

A Project can be removed when all its task groups are `cancelled` or `completed`. The Gateway deletes those groups, their subtasks, comments, questions, agent thread rows, check records, review records, old workspace lease rows and their operations, Jev decisions, and linked problem records in the same transaction as the Project.

If any group has another status (`backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`, `waiting_for_review`, or `failed`), removal is blocked. The Gateway refuses the request with HTTP 409 and `project.has_task_groups` and leaves the Project and its task records unchanged.

Removal reads and deletes no task VM row: the task VM tables are dropped ([old paths](/reference/old-paths#the-task-vm-tables)).


Extensions take part in the removal through the `ProjectRemovalHook` [plug-in point](/reference/extensions#plug-in-points), in the same transaction. The documents extension removes the Project's documents and records their stored bytes for cleanup, whether or not its switch is on.

## Errors

The Gateway returns these codes for Project requests.

| Code | Cause |
| --- | --- |
| `project.identity_conflict` | A create retry differs from the stored Project. |
| `project.repository_identity_conflict` | Another Project owns the repository. |
| `project.default_branch_unavailable` | The Gateway cannot read the remote, or the branch is missing. The message names the App or the GitHub CLI login. It holds no Git output or credentials. |
| `github.cli_unauthenticated` | A `gh_cli` read found no `gh` on the Gateway, or no `github.com` login for the `orbit` user. |
| `project.slug_conflict` | Another Project has the slug. |
| `project.update_required` | The update sends no field. |
| `project.update_in_progress` | Another update of this Project is published but incomplete. Repeat that update to finish it. |
| `project.repository_preflight_failed` | A checkout is missing, has another origin, or cannot reach the new URL; or the new repository does not hold a container's seed commit. |
| `project.repository_origin_failed` | Orbit could not change or restore `origin` in a checkout. |
| `route.domain_conflict` | A new generated domain for the slug belongs to another Route. |
| `project.repository_unowned_common` | A worktree uses a repository that no Orbit checkout owns. |
| `project.source_switch_failed` | A `default` checkout cannot switch to the new default branch. |
| `project.production_ownership_changed` | A production Instance changed during the update. |
| `project.production_repository_mismatch` | A production home records another repository than the new URL. `details.instance_id` names the Instance. |
| `project.production_rebind_failed` | Orbit could not read or re-bind a production home. |
| `project.update_failed` | The update failed for a reason without its own code, and Orbit rolled back. |
| `project.has_instances` | Removal found Instances. |
| `project.has_routes` | Removal found Routes. |
| `project.has_task_groups` | Removal found a task group that is neither `cancelled` nor `completed`. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One repository, one Project

Registration must find exactly one Project from a checkout's origin. Comparing URLs as strings was rejected, because an SSH and an HTTPS URL would allow two Projects for one repository. Letting Projects share a repository and taking the first match was rejected, because the result would depend on database order.

### One way to create a Project

`project:create` is the only way to create a Project, so every field, such as source access, is set in one place. Creating a Project during registration was rejected. That second path needed its own options, prompts, and conflict codes, and could not set every field. A private repository that needs the GitHub CLI failed there before the operator could choose the setting.

### Updates are separate from creation

Creation stays idempotent: a changed value is a conflict, never an update. A slug, URL, branch, or root change touches checkouts, Routes, and runtimes on several Nodes, so it runs as one recorded operation. A plain database update would leave checkouts, domains, and document roots out of step.

### Recovery goes forward after publication

Until a replacement Route cuts over or the Project record changes, the old values are still in effect, so a rollback is safe. After that point, clients can already see the new domain. Rolling back would change a public name twice. So a failure after publication is retried forward. Deciding by status alone was rejected: an update in `publishing` that failed before its first cutover kept the old domain live, yet blocked every other update and left its replacement Route and origin changes behind until someone repeated the exact request.

An unpublished update that stopped is rolled back when a different request arrives, instead of returning `project.update_in_progress`. The Instance locks prove that it is not running, and rolling it back changes nothing that clients see. Refusing would leave the operator with no supported way out when the stopped request keeps failing.

### Type decides capabilities

Instances of one repository share one serving contract. Per-Instance route or PHP-FPM flags were rejected. A Laravel package or a monorepo must not publish a domain or keep an idle PHP-FPM master, so only `laravel-app` and `symfony-app` get a Route by default.

### A setting routes task workspaces

A new task workspace is visitable only when the Project's `task_workspace_routed` setting says so. Choosing that from the slug `orbit` was rejected, because the engine would then know one repository. [Task workspace routing](#task-workspace-routing) stores that policy explicitly, and a later change does not reroute a workspace that already exists.

### One public name without compatibility

Project is the only public name for the repository record. Orbit has one operator, who does not value legacy support, so compatibility paths, aliases, inert endpoints, and conversion windows are removed by default without waiting for fleet migration or another confirmation. A second name adds code, tests, documentation, and ambiguity without protecting a supported user population.

The supported surface is `/api/v1/projects`, `project:*`, the Project MCP tools, and Instance fields `project_id` and `project`. The former `/api/v1/apps` routes, their nested Process and Schedule definition routes, and generated `app-*` MCP tools are removed. Project creation requires an explicit `type`; there is no path-specific default for an old endpoint. CLI Project selection uses `--project`, not `--app`. [Dependency views](/reference/instance-dependencies) read Swamp results for a Project or Instance; they start no scan or update.

The same rule applies beyond the repository record: `--wireguard-ip` has no `--wireguard-address` alias, and the former task driver settings had no `ORBIT_TASKS_AGENT_DRIVER` fallback. Older clients that depend on removed names must be updated; Orbit provides no compatibility period.

Retaining `/apps` until clients migrate is rejected, because there is no other user's migration to protect. Keeping aliases because they seem cheap or harmless is rejected, because they obscure the supported interface and still need maintenance. A conversion window or an inert endpoint is rejected, because neither provides value to this operator. Public compatibility cleanup and [stored record naming](#project-and-instance) are separate decisions; coupling them would mix interface removal with database and class renaming. Unrelated names such as the GitHub App and Node roles are not compatibility aliases for Project.

### Project and Instance

Project is the repository record. Instance is one running copy of a Project on a Node. Those are the only names for this domain: the model, the table, the foreign key, the class, the API, the CLI, and these docs. Nothing keeps a second name as an alias, a route, a JSON field, a class, a table, or a column.

The model for the repository record is `Project`, and the model for the running copy is `Instance`. Tables are `projects`, `instances`, and `instance_*`. Project update rows are `project_updates`. Foreign keys are `project_id` and `instance_id`. A path parameter that identifies a Project is `{project}`.

Project actions live in `Actions/Projects`. A class is `Project*` or `Instance*` according to whether it belongs to the repository record or the running copy. The Gateway, the CLI, the PHP SDK, and the web app follow that rule. Laravel's root namespace stays `App\`, so those actions are `App\Actions\Projects`.

Environment placeholders are `{{instance.domain}}` and `{{instance.environment}}`. A stored value that contains any other `{{...}}` placeholder is invalid. Synchronization returns `env.reference_unavailable`. A write that fails placeholder validation returns `env.configuration_invalid`. Project has no morph alias. The public subject type is `project`. The Instance morph alias is `instance`. Stored Project activity class names are `App\Models\Project`.

Realtime types are `project.created`, `project.updated`, and `project.deleted`. Payload classes are `ProjectData`, `InstanceData`, and `InstanceDeploymentData`. Project error codes use the `project.` prefix. `project.has_instances` means removal found Instances. `node.has_instances` means the Node still has Instances. `instance.placement_unavailable` means placement failed.

Monorepo folders such as `apps/cli` and `apps/gateway`, the Laravel root namespace `App\`, `app/` source folders, `config/app.php`, `APP_*` variables, Node roles `app-dev` and `app-prod`, the GitHub App, the type `laravel-app`, the Route kind `app`, and the node storage setting `apps` are not this domain.

Keeping a stored name that differs from Project or Instance was rejected, because every reader would translate. An alias that accepts another name was rejected, because an alias is a second supported path.

Renaming the monorepo folders, the Laravel root namespace, the `app/` source folders, `config/app.php`, the `APP_*` variables, Node roles, the GitHub App, the type `laravel-app`, the Route kind, or the node storage setting was rejected, because those names are not the Project or Instance record.

Reading a stored placeholder or activity class under a different spelling, as if it were the current name, was rejected: that reading is an alias, and synchronization and activity resolution then fail on rows that use the other spelling. There is no compatibility view and no dual-write. A caller that sends a removed field, or code that imports a removed class, fails. There is no compatibility period.

A repository check fails when an App-domain name is present in app code, database code, the SDK, the CLI, web sources, or these pages. A monorepo path under `apps/` is not that name. A `covers:` glob names a file that exists, and the check rejects an App-domain name left in that glob. The same check rejects the Node names the web app used before [Concepts](/concepts) settled on Nodes with roles and roleless Nodes.
