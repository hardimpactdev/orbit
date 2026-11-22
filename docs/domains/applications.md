---
title: "Applications"
description: "How a Project becomes an Instance on a Node: create a development container, configure its runtime and database, deploy to production, and remove it."
covers:
  - apps/gateway/app/Actions/Instances/{StartDevelopmentInstanceCreateAction,RegisterInstanceAction,RenameInstanceAction,ListInstancesAction,ShowInstanceAction}.php
  - apps/gateway/app/Domain/Instances/DatabaseClone/**
  - apps/gateway/app/Domain/Instances/{InstanceState,InstanceSourceLayout,ComposerSourceClassifier,Development*,InstanceLifecycle*,InstanceRegisterResult}.php
  - apps/gateway/app/Infrastructure/Instances/RemoteDevelopmentInstanceConfigurator.php
  - apps/gateway/{app/{Http/Controllers/Api/InstancesController.php,Http/Requests/Instances/**,Data/Instances/**,Models/Instance.php},database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/cli/app/{Commands/Instances/{CreateInstanceCommand,RegisterInstanceCommand,RenameInstanceCommand,ListInstancesCommand,ShowInstanceCommand,InstanceOutput}.php,Services/Git/**}
  - apps/agent/src/commands/registration_tests.rs
---

# Applications

A [Project](/reference/projects) tracks one Git repository and its defaults. An Instance is a running copy of that Project on a Node. Every new development Instance runs in an [Instance container](/reference/development-containers), while existing host checkouts keep working until migrated. Production Instances run on `app-prod` Nodes with release directories, created through [cloning](/reference/instance-cloning). The Project [type](/reference/projects#project-types) decides whether an Instance gets a Route and PHP-FPM by default.

Each stage of an Instance's lifecycle has a dedicated command:

| Stage | Command | Details |
| --- | --- | --- |
| Record the repository | `project:create` | [Projects](/reference/projects) |
| Create a development container | `instance:create` | [Create a development Instance](#create-a-development-instance) |
| Retry an existing checkout registration | `instance:register` | [Register an existing checkout](#register-an-existing-checkout) |
| Run setup commands | `instance:setup` | [Instance setup and teardown](/reference/instance-setup) |
| Clone to development or production | `instance:clone` | [Instance cloning](/reference/instance-cloning) |
| Deploy a release | `instance:deploy` | [Production release layout](/reference/deployments) |
| Record a renamed branch | `instance:rename` | [Record a renamed branch](#record-a-renamed-branch) |
| Move a host checkout into a container | `instance:migrate` | [`instance:migrate`](/cli/instance/migrate) |
| Sleep or wake a container | `instance:sleep`, `instance:wake` | [Sleep and wake](/reference/development-containers#sleep-and-wake) |
| Restore a failed host removal | `instance:restore` | [`instance:restore`](/cli/instance/restore) |
| Move to another Node | `instance:transfer` | [Instance transfer](/reference/instance-transfer) |
| Remove an Instance | `instance:destroy` | [Instance removal](/reference/instance-removal) |
| Forget a host checkout without deleting files | `instance:unregister` | [Unregister a host checkout](/reference/instance-removal#unregister-a-host-checkout) |

## Create a development Instance

Pick an active Linux Node with the `app-dev` role. Create the Project's `default` Instance first; every other development container on that Node clones from it. Passing the Project's `default_branch` as the name (such as `main`) automatically maps to `default` (see [A default-branch name means default](#a-default-branch-name-means-default)).

```bash
orbit instance:create <project> <node> default
orbit instance:create <project> <node> feature-one --branch=release
```

Orbit returns HTTP 202 with an Operation. During creation, Orbit reserves the Route and ports, renders `.env` from the [Project environment template](#project-environment-template), prepares the source, sets up or clones the database, runs the Project's setup steps, and starts its [development Processes](/reference/processes-and-schedules#project-definitions).

Container clones finish the primary `instance.create` Operation as soon as the workspace and agent thread are ready, and return `completion_operation_id` for the remaining background steps. Pass `--wait-active` in the CLI to wait for both Operations to finish.

### Branch selection

The branch is chosen independently of the Instance name:

| Input | Selected branch |
| --- | --- |
| `default`, no `--branch` | The Project's `default_branch`. |
| Another name, no `--branch` | Matching origin branch, otherwise existing local branch, otherwise a new branch with that name from `default_branch`. |
| Any name with `--branch=<branch>` | Requested origin branch, otherwise existing local branch, otherwise a new branch from `default_branch`. |

For example, `instance:create <project> <node> t3-1a2b3c4d --branch=t3code/1a2b3c4d` creates `t3code/1a2b3c4d` from the fetched default commit if it does not exist yet. Existing local branches are never reset, and Orbit never falls back to another branch if `default_branch` is missing. Invalid branch names fail with `validation.failed` before anything changes.

When Orbit converts a default-branch name to `default`, `--branch` keeps its meaning: `instance:create <project> <node> main --branch=release` creates `default` pinned to `release`. The Operation result includes `name_converted_from` when a conversion occurred, and `instance:show` reports `selected_branch`, `branch_override` (null when inherited), and the starting commit.

### Tracking progress and retries

Follow the returned Operation with `operation:show` using `poll_after_seconds` and `after_sequence`:

```json
{
  "data": {
    "id": 123,
    "kind": "instance.create",
    "target_type": "instance",
    "target_id": 15,
    "status": "running",
    "poll_after_seconds": 2
  }
}
```

Creation moves through `reserved`, `source_resolved`, and `active`. If a step fails, Orbit keeps the Instance and source, records `failed_step` and `error_code`, and leaves `first_setup_pending` set so you can fix the issue and retry the exact same `instance:create` command. Changing the Project, Node, root, or branch override on a retry returns `instance.placement_conflict`. Use [Instance removal](/reference/instance-removal) to discard an abandoned reservation.

After activation, you can commit and move `HEAD`, while keeping the recorded branch checked out. Update the recorded branch with [`instance:rename`](#record-a-renamed-branch) after renaming it in Git; [removal](/reference/instance-removal#native-order) refuses a mismatched branch with `instance.source_branch_mismatch` (even with `--force`), and [cloning](/reference/instance-cloning#candidate-rules) refuses it with `instance.clone_candidate_branch_invalid`.

### Refusal codes

Orbit refuses invalid create requests before changing anything:

| Code | HTTP | Cause |
| --- | --- | --- |
| `project.source_defaults_incomplete` | 409 | The Project has no valid default branch or root. |
| `operation.kind_unavailable` | 503 | The Project uses `gh_cli` source access, which `instance:create` does not support. |
| `instance.node_inactive` | 409 | The Node is not an active Linux Node. |
| `instance.node_not_app_dev` | 409 | The Node has no active `app-dev` role. |
| `instance.candidate_required` | 409 | The Node has `app-prod`; use [`instance:clone`](/reference/instance-cloning) instead. |
| `instance.placement_unavailable` | 409 | The Node does not hold exactly one active `app-dev` or `app-prod` role. |
| `instance.default_missing` | 409 | A non-default container create found no active `default` container with a seed snapshot on that Node. |
| `instance.container_required` | 409 | The `default` Instance on that Node is a host checkout; migrate it to a container first. |
| `instance.default_seed_in_flight` | 409 | A nested-fleet `default` container is currently retaking its seed snapshot. |
| `instance.path_taken` | 409 | Another managed Instance already uses the target path. |
| `instance.default_path_occupied` | 409 | The `default` path overlaps another managed Instance. |
| `instance.removal_conflict` | 409 | A retry arrived while the Instance is being removed. |
| `instance.route_unserved` | 422 | `--domain` was passed for a Project type that does not serve web traffic. |
| `route.domain_invalid` | 422 | The requested domain is invalid or longer than 64 characters. |

### Dependency copy

Host-checkout worktrees start from the `default` checkout on the same Node at its last [deployed commit](/reference/deployments#development-defaults). The API exposes `seed_path` and `seed_commit` on `default`, and Orbit passes `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` to Project [setup steps](/reference/instance-setup).

Setup steps can reflink disposable dependency and cache directories (such as `vendor` or `node_modules`) using `cp -a --reflink=auto` (or `--reflink=always` when shared blocks are required), followed by locked installs. Never copy `.env`, logs, databases, `public/hot`, or configuration caches. If `default` has not deployed yet, the seed variables are empty and setup installs from lockfiles.

### Database clone

When a Project's `default` Instance has a database attached under prefix `DB`, every new development Instance gets its own copy before setup steps run. The copy keeps the same engine as the source:

| Source | Copy behavior |
| --- | --- |
| MySQL on a [Database server](/reference/database-servers) | Creates `<project>_<instance>`, its `_test` database, and an Instance user on the same server, then streams `mysqldump --single-transaction` into it. |
| PostgreSQL on a [Database server](/reference/database-servers) | Creates a template-cloned database and test database on the same server for container clones. |
| SQLite file inside `default` | Copies a SQLite backup snapshot at the same relative path (or inherits it via ZFS on container clones). Tests use `:memory:`. |
| Valkey on a [Database server](/reference/database-servers) | Creates a dedicated per-clone Valkey user on container clones. More than one Valkey target is refused. |

Shared database attachments (`shared` mode) are inherited directly rather than cloned. Orbit records each owned copy under connection slug `<project>-<instance>`, attaches it under `DB`, and synchronizes `.env` and `.env.testing`. If the Instance has no stored environment yet and an existing `.env` is present in its [application directory](/reference/projects#application-directory), Orbit [imports](/reference/environment-variables#import) it first so existing keys stay intact.

The clone copy holds the full data of `default`, including any personal data. A Project without a `default` Instance, or whose `default` has no `DB` attachment, skips cloning.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.database_clone_unsupported` | 422 | The source database kind cannot be cloned, more than one Valkey target is attached, or `--database-server` conflicts with `default`'s server on a container clone. |
| `instance.database_slug_taken` | 409 | The derived SQLite connection slug is already in use. |
| `instance.database_clone_failed` | 502 | The copy failed. Orbit drops the partial copy and removes the Instance on initial create. |

If a failed removal cannot finish, the error reports `cleanup: incomplete` and names the `instance:destroy` command to run. No teardown step runs because setup has not started yet. On a retry after an interrupted initial create, Orbit finishes any incomplete copy and runs setup without deleting the Instance if the retry fails again.

### Database on a server

An Instance that does not clone from `default` (such as `default` itself) can create an empty database during `instance:create` by passing `--database-server` (API field `database_server`). The caller needs access grants to both the Instance's Node and the Gateway.

```bash
orbit instance:create 12 3 default --database-server=beast-mysql
```

After preparing source and before running setup steps, Orbit creates `<project>_<instance>`, its `_test` database, and an Instance user on that [Database server](/reference/database-servers), attaches it under `DB`, and renders `.env` and `.env.testing`.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.database_server_conflict` | 422 | The Instance already receives a [copy](#database-clone) of `default`'s database. |
| `instance.database_server_existing` | 409 | The Instance already finished its first setup; use `database:create --instance` and `instance:setup` instead. |
| `database.server_missing` | 404 | No Database server matches that slug. |
| `database.server_inactive` | 409 | The Database server is not active. |
| `database.driver_unsupported` | 422 | The target is a Valkey server, which does not hold Instance databases. |
| `instance.database_create_failed` | 502 | Orbit could not finish creating the database. |

Standard `database:create` errors (such as `database.name_conflict` and `database.slug_conflict`) also pass through. On initial create, any failure after admission removes the Instance and owned database; on a retry of an interrupted create, Orbit leaves the Instance in place. Repeating a finished create whose Instance already owns a database on that server returns HTTP 200 without changes (or `instance.setup_step_failed` if its last `instance:setup` failed).

## Record a renamed branch

Rename the branch in Git first, then tell Orbit so its records match:

```bash
git branch -m t3code/login-redirect
orbit instance:rename <instance> --branch=t3code/login-redirect
```

The API endpoint is `POST /api/v1/instances/{instance}/rename`. The MCP tool is `instance-rename`. Send `instance` and at least one of `branch` or `domain`. It applies only to active development Instances and returns immediately with the updated Instance, without starting an Operation. The Instance ID, name, path, layout, starting commit, and Node stay unchanged.

Orbit verifies the branch against the [Node agent's checkout report](/reference/node-agent#checkouts). Symbolic `HEAD` must already point to the supplied branch and the checkout root and origin must match, or Orbit refuses with `instance.branch_not_checked_out` (including when the Node agent has not reported recently). Dirty or unpushed commits are allowed.

Updating the branch sets both `selected_branch` and `branch_override`, pinning even a `default` Instance to that branch. Passing the already recorded branch keeps the existing override and makes no writes. Passing `domain` returns `operation.kind_unavailable`, and a concurrent lifecycle operation returns `instance.lifecycle_busy`. See [`instance:rename`](/cli/instance/rename) for full details.

## Register an existing checkout

New host registrations are refused with `instance.container_required` (409). Use [`instance:create`](/cli/instance/create) to create a container instead. The behavior below applies only to retrying an existing registered host checkout at `<apps-root>/<project-slug>/<name>` on its `app-dev` Node.

```bash
cd /fast/apps/acme/feature
orbit instance:register --project=12 --name=feature --yes
orbit instance:register --project=12 --setup --domain=feature.acme.test --yes
```

Orbit verifies the checkout's owner, path, Git directory, origin, branch, and framework metadata from the [Node agent's checkout report](/reference/node-agent#checkouts), keeping Git `HEAD`, dirty files, and your `.env` intact. Without `--setup`, `--domain`, or a world-readable `.env`, Orbit records the active Instance immediately (HTTP 201). With any of those options, Orbit returns HTTP 202 and runs an `instance.register` Operation (see [`instance:register`](/cli/instance/register)).

A missing or stale checkout report returns `instance.source_invalid`. Registration transfers source ownership to Orbit so a later `instance:destroy` deletes the checkout; to release a host checkout without deleting its files, use [`instance:unregister`](/reference/instance-removal#unregister-a-host-checkout). The CLI requires confirmation (`--yes` in noninteractive or JSON mode). Create the Project with [`project:create`](/cli/project/create) first; names default to the directory name (lowercase DNS labels up to 63 characters), and a name matching the Project's default branch becomes `default`.

## Provision the application endpoint

Before preparing source, Orbit assigns a [Vite port](/reference/assigned-vite-ports) and [SSR port](/reference/assigned-ssr-ports) and reserves the Route domain (`--domain` if given, otherwise generated from the Cluster or Node TLD; see [Routes](/reference/routes#select-a-domain-and-scope)). Only `laravel-app` and `symfony-app` Projects receive a Route automatically.

The PHP version and framework type come from the Project's source facts. An Instance becomes `active` only after source preparation, `.env` rendering, setup steps, and development Processes all succeed. Repeating an identical create on an already active Instance skips source and setup work (or returns `instance.setup_step_failed` if a later manual `instance:setup` failed).

## Laravel application URL

For web-serving Projects, Orbit inspects the [application directory](/reference/projects#application-directory) derived from the effective web root without running Composer, Artisan, or application code. For non-package Projects, Orbit recognizes Laravel when the directory has a regular `artisan` file and a `composer.json` declaring `laravel/framework` once in `require` or `require-dev`. A `composer.json` without those markers is plain PHP; no `composer.json` means no PHP runtime. `laravel-package` Projects are exempt from requiring `artisan`.

| Problem | Code |
| --- | --- |
| Non-package Project has `artisan` without `laravel/framework`, `laravel/framework` without `artisan`, duplicate declarations, or a symlinked `artisan` | `app-dev.laravel_source_invalid` |
| `composer.json` is missing while `artisan` exists, or `composer.json` is a symlink, non-regular file, or owned by another user | `app-dev.source_metadata_unsafe` |
| `composer.json` is invalid JSON, or no supported PHP version satisfies its constraint | `app-dev.php_version_unsupported` |

For Laravel Instances, Orbit writes `APP_URL=https://<route-domain>` atomically:

- If `.env` exists, Orbit updates or adds `APP_URL` and fills an empty `APP_KEY`, leaving all other lines untouched.
- If `.env` is missing, Orbit creates it from `.env.example` (or empty) with `APP_URL`, a generated or stored `APP_KEY`, and `APP_NAME`.
- If `bootstrap/cache/config.php` exists, Orbit updates its cached `url` entry.

A symlinked file or cache directory, duplicate `APP_URL` lines, or an unreadable cached value stops provisioning with `app-dev.laravel_url_configuration_failed`. After activation, the [stored environment](/reference/environment-variables) owns `APP_URL`.

### Symfony applications

A `symfony-app` Project checks for a regular `bin/console` file and `symfony/framework-bundle` in `composer.json` `require`. Missing pairs or a symlinked `bin/console` return `app-dev.symfony_source_invalid`, and `bin/console` without `composer.json` returns `app-dev.source_metadata_unsafe`. Orbit never writes `APP_URL` for Symfony Instances; manage their environment with [`env:update`](/reference/environment-variables).

## Project environment template

Orbit maintains one environment template per Project from the committed `.env.example` in the application directory on the default branch. It captures the template on project creation, source changes, and container default deployments ([container source facts](/reference/deployments#container-source-facts)).

For GitHub App Projects with an Instance checked by the automatic deploy policy, Orbit checks `.env.example` whenever a new default-branch head appears, downloading the file only when its Git blob SHA changes. A refresh failure never blocks head checks and leaves the existing template untouched.

When `.env.example` changes, the template updates by these rules:

- New keys take their example values.
- Unedited keys matching the previous example take the new example values.
- Values edited by an operator stay unchanged.
- Keys removed from `.env.example` stay in the template marked removed.

Existing Instances keep their current stored environment. New Instances created with `instance:create` render from the latest template combined with `APP_NAME`, `APP_ENV`, `APP_DEBUG`, and (for Laravel) a fresh `APP_KEY`.

## Production Instances

`instance:create` refuses `app-prod` Nodes with `instance.candidate_required`. Always create a production Instance by [cloning](/reference/instance-cloning) an existing development or production Instance, then run its first [deployment](/reference/deployments). Each Project can have at most one production Instance per `app-prod` Node, with its own Unix user, home directory, and [PHP-FPM service](/reference/php-runtime#production-runtime).

## Set the web root

An Instance inherits its Project's root unless you pass `--root` to store a relative override (at most 255 bytes, using only `A-Z`, `a-z`, `0-9`, `.`, `_`, and `-`, with no `.` or `..` segments or leading/trailing slashes). Package Projects also accept `.` for the repository root. On production Instances, the root resolves inside `<home>/current`.

## Input boundary

`instance:create` and `instance:destroy` accept no repository URLs, shell commands, or arbitrary paths because the Project owns the repository. Registration accepts only source facts verified by the Gateway against the calling Node.

## Move a checkout Instance into a container

[`instance:migrate`](/cli/instance/migrate) moves a legacy host-checkout development Instance into an [Instance container](/reference/development-containers#migrate-a-checkout-instance) on the same Node, preserving its branch, unpushed commits, untracked and ignored files, and Route while leaving its database in place. Migrate the Project's `default` Instance first; if migration fails, the original checkout continues serving. See [Old paths](/reference/old-paths).

## Why it works this way

These design choices explain the rules above.

### Registration takes ownership

Registered checkouts share the same managed lifecycle, safety checks, and removal flow as created Instances. Observing external worktrees without owning them was rejected because it left Orbit serving paths it could not safely move or clean up.

### The default Instance keeps its name

Naming the main development Instance `default` keeps its path and Route stable even if the Project's default branch changes. Naming it after the branch would move its directory and domain on every branch rename, and a second alias like `main_branch` was rejected to keep one name per setting.

### A default-branch name means default

Callers and agents often pass the default branch name (such as `main`) when they mean the Project's default development Instance. Converting that name to `default` and reporting `name_converted_from` avoids creating a duplicate `main.<slug>.<tld>` Instance while avoiding an unnecessary refusal round trip.

### An explicit branch stays explicit

Orbit stores `--branch` in `branch_override` even when it matches `default_branch`, because comparing strings later cannot distinguish an intentional pin from inheritance. When a Project changes its default branch, only a `default` Instance with no override switches automatically.

### Development branch reconciliation

Agents often start on a temporary branch (such as `t3code/1a2b3c4d`) and rename it locally once the task takes shape. Keeping branch names independent of Instance names lets branches contain `/` without breaking DNS labels or managed paths. Branch recording is explicit and read-only so Orbit never mutates Git state behind the user's back, and `--force` removal still refuses a mismatched branch so Orbit never deletes the wrong checkout.

### Active does not mean healthy

An Instance becomes `active` once its source, runtime, Route, and `APP_URL` are ready. New apps often lack dependencies or database migrations until after creation, so waiting for an HTTP 200 health gate was rejected.

### Orbit owns the Laravel URL

Laravel relies on `APP_URL` for URLs generated outside HTTP requests. Deriving `APP_URL` from the Route keeps links working automatically whenever a domain changes; inferring the domain from `.env` was rejected because the Route is the source of truth.

### Each new Instance copies the default database

Sharing one development database across Instances causes test runs and migrations to clobber each other's data (see [Tests get their own database](/reference/database-connections#tests-get-their-own-database)). Copying `default`'s database automatically avoids an opt-in flag that callers might forget, and keeping the same engine avoids cross-engine type mismatches.

### Create the database before setup

Running `database:create --instance` only after `instance:create` meant setup steps like migrations failed on the first run. Creating the database inside `instance:create` before setup solves that while keeping retry behavior safe: only Instances marked with `first_setup_pending` resume through `instance:create`, so an existing live Instance is never deleted on a setup failure.

### Copy dependencies, not the checkout

Projects know their own dependency folders (including nested monorepo packages), whereas copying an entire checkout brings along Instance-specific `.env` and runtime state. Passing `ORBIT_SEED_PATH` to Project setup steps lets each Project reflink only clean dependency directories.

### One application model

The Instance is Orbit's only runnable application model. Avoiding parallel legacy models keeps every lifecycle rule, check, and command consistent.
