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

A Project records one Git repository and the defaults for running it. New [Instances](/domains/applications) inherit its default branch and web root, and its type determines whether they get a Route and PHP-FPM. The API path is `/api/v1/projects`, and the CLI family is [`project`](/cli/project).

## Create a Project

Create a Project before creating or registering its first Instance:

```bash
orbit project:create acme laravel-app git@github.com:acme/site.git
orbit project:create acme laravel-app git@github.com:acme/site.git --default-branch=stable --root=web/public
orbit project:create leden laravel-app git@github.com:acme/leden.git --source-access=gh_cli
```

In the CLI, `--root` defaults to `.` for package types and `public` for other types; the API and SDK require `root` explicitly. If you omit `--default-branch`, Orbit reads the remote default branch once using the Project's [source access](#source-access) and stores it. An explicit branch must exist on the remote.

### Retry creation safely

Creation is idempotent. Repeating `project:create` with the same name, slug, type, repository URL, source access, default branch, root, task check, and `task_workspace_routed` returns the existing Project without re-reading the remote for an omitted branch. An omitted source access means `github_app`, and omitting `task_workspace_routed` keeps the stored value.

Sending any different value on a retry (including an equivalent HTTPS vs. SSH URL for the same repository) fails with `project.identity_conflict` and changes nothing.

## Project types

Every Instance of a Project shares its type and serving capabilities:

| Type | Route | PHP-FPM | Root `.` allowed | Default `task_check` |
| --- | --- | --- | --- | --- |
| `laravel-app` | One default Route per active Instance | Yes | No | none |
| `symfony-app` | One default Route per active Instance | Yes | No | none |
| `monorepo` | Explicit Routes only | Only on Routes targeting a Laravel source | No | none |
| `laravel-package` | Explicit Routes only | No | Yes | none |
| `node-package` | Explicit Routes only | No | Yes | none |

A Route cannot target an Instance whose root is `.`; set a relative web root first. A `laravel-package` Project does not require an `artisan` file.

A `monorepo` selects its PHP version from the root `composer.json` (or uses no PHP if absent) without requiring Laravel. Updating `source_access` or deploying a container `default` refreshes source facts (see [Container source facts](/reference/deployments#container-source-facts)). A `symfony-app` gets a Route and PHP-FPM pool without Laravel `.env` or config-cache writes (see [Symfony applications](/domains/applications#symfony-applications)).

## Source access

`source_access` controls how Orbit reads a private `github.com` repository for default-branch lookups, branch resolution, clones, and fetches:

| Value | How Orbit authenticates |
| --- | --- |
| `github_app` | Uses the Gateway's [GitHub App](/reference/github-app#how-orbit-reads-a-repository) when installed on the repository, otherwise reads unauthenticated. Default. |
| `gh_cli` | Uses the Gateway `orbit` user's [GitHub CLI login](/reference/github-app#read-through-the-github-cli). Only valid for `github.com` URLs. |

Public repositories and non-GitHub hosts work with `github_app`. A `gh_cli` Project cannot run [Tasks](/reference/tasks) or container `instance:create`.

## Repository identity

Orbit normalizes the host and path of `repository_url` into a canonical repository identity so equivalent SSH and HTTPS URLs (with or without `.git`) match. Creating a second Project for the same repository fails with `project.repository_identity_conflict`.

## Registration needs a Project

[`instance:register`](/domains/applications#register-an-existing-checkout) repairs only an already-registered host Instance row at that checkout path and requires an explicit Project ID (`--project`). Creating a Project does not make a fresh host checkout registerable: any path without an existing Instance row fails with HTTP `409` `instance.container_required` (use [`instance:create`](/cli/instance/create) to create a container Instance instead).

## Application directory

For Laravel Instances, the application directory is the effective web root without its trailing `/public` segment (`public` maps to the checkout or release root; `apps/site/public` maps to `apps/site`). Orbit derives this automatically from the Instance's root override or the Project's `root`.

The application directory holds `composer.json`, `artisan`, development [environment files](/reference/environment-variables#where-the-file-lives), and Laravel [logs](/reference/instance-logs#know-which-file-the-gateway-reads), and serves as the working directory for PHP-FPM, default Instance Processes, and Instance Schedules. Setup, teardown, deploy steps, and task checks still run from the repository root (with `VP_HOME` exported to the Node's Vite+ store). An Instance can serve extra directories through [Routes with a web root](/reference/routes#serve-several-web-roots).

## Fields

API responses, the SDK, MCP tools, and CLI JSON expose these fields:

| Field | Meaning |
| --- | --- |
| `slug` | Unique lowercase identifier (at most 63 characters) used for checkout paths and generated domains. |
| `name` | Display name (defaults to `slug`). |
| `code` | Unique three-letter uppercase code used on Task cards. See [Project codes](#project-codes). |
| `type` | `monorepo`, `laravel-app`, `symfony-app`, `laravel-package`, or `node-package`. |
| `repository_url` | HTTPS or SSH Git URL used for fetching. |
| `source_access` | `github_app` (default) or `gh_cli`. |
| `default_branch` | Branch for `default` Instances and base for new branches. |
| `root` | Repository-relative web root inherited by Instances (such as `public` or `apps/site/public`). |
| `container_server` | `conn` (default) or `t3` for new dev containers. Task copies always run `conn`. |
| `nested_fleet` | Boolean (default `false`) for Projects whose Instances run an inner fleet. |
| `task_check` | Optional command run for task baselines and handoffs (default `null`). |
| `default_auto_deploy` | Boolean (default `true`) enabling automatic deploys on container `default` Instances. |
| `task_workspace_routed` | Boolean (default `true`) controlling whether new task workspaces get a Route. |
| `review_and_merge` | Boolean (default `false`) enabling Orbit's pull request review and merge flow. |
| `merge_check` | Required GitHub check run name for [handoff CI](/reference/tasks#handoff-ci) and merges (default `null`). |
| `task_implementer_*` | Default implementer `provider` (`claude`, `codex`, `antigravity`), `model`, and `effort` for [task copies](/reference/tasks#copy-turn-agents). |
| `task_reviewer_*` | Default reviewer `provider`, `model`, and `effort` for task copies. |
| `task_pr_mode` | Default [PR mode](/reference/tasks#task-group-modes) (`review_before_pr` or `pr_when_done`) for task copy groups. |

### Project summary

`GET /api/v1/projects` (`project:list`) adds a summary to each Project. Conn clients read it to tag a General thread to a Project, so Orbit stays the only registry of Projects. Any Node with access to at least one Node may call it. It lists the Projects with an Instance on a Node the caller can reach; the Gateway sees every Project.

| Field | Meaning |
| --- | --- |
| `repository` | The [repository identity](#repository-identity), such as `github.com/acme/site`. It holds no credentials. |
| `node_name` | The Node of the Project's `default` Instance, or `null` without one. |
| `icon`, `color` | Always `null`: Orbit stores no Project icon or color. |
| `instance_count` | The Project's Instances on Nodes the caller can reach; every Instance for the Gateway. |

`project:show`, `project:create`, and `project:update` return no summary.

### Project codes

Each Project gets a unique three-letter uppercase `code`, derived from its slug unless sent on `POST /api/v1/projects`. The code stays fixed when the slug changes.

To change a code, update it in the web app or send `PATCH /api/v1/projects/{project}` with `code` alone (combining `code` with `type`, `slug`, `repository_url`, `source_access`, `default_branch`, or `root` returns `project.code_update_separate`). Invalid codes return `project.invalid_code`, duplicates return `project.code_conflict`, and exhaustion returns `project.codes_exhausted`.

## Update a Project

Use `project:update` (or `PATCH /api/v1/projects/{project}` / MCP `project-update`) to change one or more fields; omitted fields stay unchanged.

```bash
orbit project:update 3 --repository=https://github.com/acme/site.git --default-branch=stable
orbit project:update 14 --source-access=gh_cli --default-branch=main
```

Each field takes effect as follows:

| Field | Effect |
| --- | --- |
| `name` / `--name` | Updates immediately (at most 255 characters); leaves slug, domains, and code unchanged. |
| `type` / `--type` | Updates immediately. Must stay compatible with `root` (`.` is invalid for non-package types). |
| `slug` / `--slug` | Updates generated Routes without moving existing checkout folders or production homes. |
| `repository_url` / `--repository` | Updates `origin` on host checkouts, re-binds production homes, and verifies container seed commits. |
| `source_access` / `--source-access` | Verifies remote default-branch access with the new mode before saving. |
| `default_branch` / `--default-branch` | Must exist on the remote; switches `default` development Instances without a `branch_override`. |
| `root` / `--root` | Updates effective web root and runtimes for Instances without their own root override. |
| `task_check` / `--task-check` | Sets the task check command (`null` or `--clear-task-check` clears it). |
| `task_workspace_routed` | Applies to newly created task workspaces; existing workspaces keep their routing mode. |
| `default_auto_deploy` | Takes effect on the next minute's check (see [Automatic development defaults](/reference/deployments#automatic-development-defaults)). |
| `merge_check` / `--merge-check` | Sets the required check run name (`null` or `--clear-merge-check` clears it). |
| `task_implementer_*`, `task_reviewer_*` | Sets default copy-turn agents (`--clear-task-implementer-agent` / `--clear-task-reviewer-agent` clears them). |
| `task_pr_mode` / `--task-pr-mode` | Sets the default PR mode (`--clear-task-pr-mode` clears it). |
| `container_server` / `--container-server` | Applies to dev containers reserved after the change (`conn` or `t3`; column default `conn`). Existing `t3` containers keep theirs until switched with [`instance:server:switch`](/cli/instance/server-switch); task copies always run `conn`. |

A type or root change that leaves a Route pointing at `.` fails with `route.target_web_root_unsupported`. Because Orbit stores each checkout's directory path at creation, changing `slug` never breaks older checkouts.

### Change source access

Orbit verifies the remote default branch using the new `source_access` value before applying the update. If that check fails, nothing changes. Any `repository_url` or `default_branch` in the same request is checked with the new access mode.

### Task workspace routing

`task_workspace_routed` accepts a JSON boolean (`--task-workspace-routed=true|false` in the CLI; invalid CLI input returns `project.task_workspace_routed_invalid`). It affects only future task workspaces and never reroutes existing ones or ordinary Instances.

### Review and merge

Enable or disable review and merge through dedicated endpoints (`POST /api/v1/projects/{project}/review-and-merge/enable` and `/disable`, or `project:review-and-merge:enable` / `:disable`). `PATCH /api/v1/projects/{project}` sets `merge_check` (up to 255 characters or `null`) and refuses `review_and_merge`. Invalid CLI options return `project.merge_check_invalid` or `project.merge_check_conflict`.

```bash
orbit project:update 78 --merge-check="Required checks"
orbit project:review-and-merge:enable 78
```

Enabling requires `source_access: github_app`, a non-empty `merge_check`, and readable GitHub branch rules that do not enforce a strict up-to-date branch (strict rules return `project.strict_base`; unreadable rules return `project.branch_policy_unavailable`). In a review-and-merge Project, CI must also run `merge_check` on `task-*-handoff` pushes. See [Tasks: Review and merge](/reference/tasks#review-and-merge).

### Update lifecycle

Changes to `slug`, `repository_url`, `default_branch`, or `root` run as one recorded operation:

| Status | Work |
| --- | --- |
| `reserved` | Records the request and previous values. |
| `preflighted` | Verifies every affected checkout, production home, container seed commit, and generated domain. |
| `prepared` | Switches branches, updates origins, re-binds production homes, and stages replacement Routes. |
| `publishing` | Cuts over replacement Routes, syncs Instance URLs and environments, and publishes new Project values. |
| `cleaning_up` | Confirms no production Instance changed concurrently. |
| `complete` | Done. |

Any failure before the first visible change (a Route cutover or published Project field) rolls back cleanly to `rolled_back` (or stays `rolling_back` for an identical retry if rollback itself fails). Any failure after publication retries forward on an identical request; a different update returns `project.update_in_progress`.

If an unpublished update stalled while holding no active lock, a new update rolls it back first and then proceeds (except on Projects with no Instances, which require an identical retry). During slug updates, Instances without a stored environment skip env sync (`env.configuration_missing`) while Route convergence still updates `APP_URL` in `.env` (see [Other writers](/reference/environment-variables#other-writers)).

Git source updates run as the Node's managed user with checkout hooks and filesystem monitors disabled (`-c core.hooksPath=/dev/null -c core.fsmonitor=false`), without mutating local Git config.

### Repository changes

Sending `repository_url` always recomputes the stored repository identity, even when the URL matches, so you can send the current URL to repair identity drift or re-bind production homes (`deployment.repository_rebind_required`).

On host checkouts, Orbit updates `origin` without touching local branches or commits (and never pushes). Container Instances keep no `origin` and fetch through the Node mirror, so preflight verifies that each container's seed commit exists in the new repository (`project.repository_preflight_failed` if missing). Every production home on the release layout is preflighted and atomically re-bound if its recorded URLs match the same repository (`project.production_repository_mismatch` otherwise), without starting a deployment.

## Setup, teardown, and deploy steps

Projects own ordered [setup and teardown steps](/reference/instance-setup) for development Instances and a separate [development deploy list](/reference/deployments#development-deploy-steps) managed with [`project:dev-deploy-step`](/cli/project/dev-deploy-step-list). Commands run from the repository root; setup steps may also reference Gateway-owned steps such as `@orbit/ci-caches` (placed after dependency install to import matching CI caches). Missing or non-executable commands return `instance.setup_step_unavailable` or `instance.teardown_step_unavailable`; other failures return `instance.setup_step_failed` or `instance.teardown_step_failed`.

## Container server

`container_server` chooses which server runs inside the Project's newly reserved dev containers: `conn` (Conn Server, the default) or `t3` (T3 server). Task copies always run `conn`. Both implement the same client contract, but store threads and pairings differently, so existing containers keep the server they were created with; recreate a container to switch it. Choosing a server not pinned by the recipe returns `project.container_server_unavailable` (409). See [Container server](/reference/development-containers#container-server).

## Project Documents

[Project Documents](/reference/project-documents) (the `documents` extension) give each Project a folder tree of notes and versioned attachments stored independently of Git branches and Instances. Place Project-level `AGENTS.md` and skills in the reserved `agent/` folder so every new container clone receives them automatically (reported as `agent_instructions` on `project:show`; see [Agent instructions](/reference/development-containers#agent-instructions)).

## Maintenance

Dependency maintenance is opt-in per Project and off by default. Enable it with [`project:maintenance:enable`](/cli/project/maintenance-enable) on a `github_app` Project that has a default branch, a `task_check`, and an entry in `swamp/extensions/behaviors/operations/project-maintenance/projects.json` (otherwise runs fail with `project.maintenance_not_configured`). Disable it with [`project:maintenance:disable`](/cli/project/maintenance-disable); direct runs on disabled Projects fail with `project.maintenance_disabled` (409).

An hourly schedule inspects the default branch's lockfiles via the GitHub App (skipping unchanged blob SHAs and caching registry lookups daily), selects weekly routine update tasks, and observes open maintenance PRs to refresh the [dependency findings index](/reference/instance-dependency-contracts). You can also trigger runs directly with [`project:maintenance:inspect`](/cli/project/maintenance-inspect) or the `project-maintenance-inspect` and `project-maintenance-routine` MCP tools.

Each weekly routine admits at most one maintenance task per Project. If a maintenance task cannot leave `backlog`, Orbit records an assistance request explaining why; fix the task and rerun `project-maintenance-routine` in the same week to resume it.

## Nested fleet setting

`nested_fleet` is a boolean (default `false`) reserved for Projects whose Instances run an inner Orbit fleet (such as Orbit itself). Change it alone via the Project update API (`project.nested_fleet_update_separate` 422 if combined with other fields). Turning it on requires the Project ID in the Gateway's [trial allow list](/reference/gateway-environment#keys-in-the-example-file) (`project.nested_fleet_not_allowed` 409; malformed list returns `project.nested_fleet_projects_invalid` 500) and requires zero existing Instances (`project.nested_fleet_in_use` 409).

When enabled, every Instance runs in the `orbit-instances-nested` Incus project from the [nested-fleet image](/reference/development-containers#the-nested-fleet-image). Only development create (and its retry) and Conn create support nested-fleet Projects; clone, register, and migrate return `operation.kind_unavailable` (503).

| Project | Node | Result |
| --- | --- | --- |
| `nested_fleet: false` | `app-dev` | Container in `orbit-instances`. |
| `nested_fleet: true` | Nested hosting unavailable | Refused with `instance.nested_fleet_host_unavailable` (409). |
| `nested_fleet: true` | Retry would use a host checkout | Refused with `instance.nested_fleet_container_required` (409). |
| `nested_fleet: true` | Hosts nested fleets (switch on or CI-only nested Node) | Container in `orbit-instances-nested` (CI-only Nodes accept proof copies only; others get `instance.node_ci_only`). |

A non-default create with a host-checkout `default` returns `instance.container_required` (409). Orbit records the Incus project once at reservation and clones inherit it from `default`.

## Remove a Project

`project:destroy` removes a Project that has no Instances (`project.has_instances`) and no Routes (`project.has_routes`), deleting its Process and Schedule definitions, setup and teardown steps, update records, and Project Documents (even if the `documents` extension is disabled).

Removal succeeds when all task groups are `cancelled` or `completed`, deleting those task groups and their related records in the same transaction. Any task group in another status (`backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`, `waiting_for_review`, or `failed`) blocks removal with HTTP 409 `project.has_task_groups`.

## Errors

Orbit returns these error codes for Project requests:

| Code | Cause |
| --- | --- |
| `project.identity_conflict` | A create retry differs from the stored Project. |
| `project.repository_identity_conflict` | Another Project already owns that repository. |
| `project.default_branch_unavailable` | Orbit cannot read the remote or the branch does not exist. |
| `github.cli_unauthenticated` | `gh_cli` found no `gh` binary or `github.com` login for the Gateway's `orbit` user. |
| `project.slug_conflict` | Another Project already uses that slug. |
| `project.update_required` | Update request sent no fields. |
| `project.update_in_progress` | Another published update is incomplete; repeat that update to finish it. |
| `project.repository_preflight_failed` | Checkout is missing, has a different origin, cannot reach the URL, or lacks a container's seed commit. |
| `project.repository_origin_failed` | Orbit could not update or restore `origin` in a checkout. |
| `route.domain_conflict` | A newly generated domain collides with another Route. |
| `project.repository_unowned_common` | A worktree points to a repository not owned by Orbit. |
| `project.source_switch_failed` | A `default` checkout could not switch to the new default branch. |
| `project.production_ownership_changed` | A production Instance changed concurrently during the update. |
| `project.production_repository_mismatch` | A production home records a different repository (`details.instance_id` names it). |
| `project.production_rebind_failed` | Orbit could not read or re-bind a production home. |
| `project.update_failed` | Update failed for an unclassified reason and rolled back. |
| `project.has_instances` | Removal blocked by existing Instances. |
| `project.has_routes` | Removal blocked by existing Routes. |
| `project.has_task_groups` | Removal blocked by a task group that is not `cancelled` or `completed`. |

## Why it works this way

These design decisions explain the rules above.

### One repository, one Project

Registration must resolve a checkout's origin to one unique Project. Comparing raw URL strings was rejected because SSH and HTTPS forms would allow duplicates for the same repository.

### One way to create a Project

`project:create` is the sole creation path so all settings (including `source_access`) are configured explicitly in one place rather than inferred during checkout registration.

### Updates are separate from creation

Keeping `project:create` strictly idempotent prevents accidental mutations. Changes to `slug`, `repository_url`, `default_branch`, or `root` span checkouts, Routes, and runtimes across Nodes, so they run as a tracked operation rather than a plain database write.

### Recovery goes forward after publication

Before a replacement Route cuts over or the Project record changes, rollback is invisible to clients and safe. Once a new domain or setting is visible, retrying forward avoids flipping public names back and forth. Stalled unpublished updates are rolled back automatically when a new update arrives so operators are never stuck.

### Type decides capabilities

Instances of one repository share one serving contract. Per-Instance PHP-FPM or default-Route toggles were rejected so packages and monorepos never publish unintended domains or idle PHP-FPM pools.

### A setting routes task workspaces

Routing for new task workspaces is controlled explicitly by `task_workspace_routed` rather than special-casing a repository slug.

### One public name without compatibility

`Project` is the only public name for the repository record (`/api/v1/projects`, `project:*`, `--project`, `project_id`). Legacy `/api/v1/apps` routes, `app-*` tools, and compatibility aliases are removed rather than maintained alongside the current interface. [Dependency views](/reference/instance-dependencies) read stored scan results without starting new scans.

### Project and Instance

`Project` (repository record) and `Instance` (running copy on a Node) are the sole domain names across models (`Project`, `Instance`), tables (`projects`, `instances`, `project_updates`), foreign keys (`project_id`, `instance_id`), API routes, CLI commands, realtime events (`project.created`, `project.updated`, `project.deleted`), and placeholders (`{{instance.domain}}`, `{{instance.environment}}`). Unrelated names such as the `apps/` monorepo folder, Laravel's `App\` namespace, `APP_*` env vars, `app-dev`/`app-prod` roles, `laravel-app`, and the GitHub App are outside this domain and stay as they are.
