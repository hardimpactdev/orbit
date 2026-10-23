---
title: "Instance setup and teardown"
description: "How a Project stores named setup and teardown commands, and when Orbit runs them for a development Instance."
covers:
  - apps/gateway/app/Domain/Projects/{LifecyclePhase,LifecycleStep,ProjectLifecycleRunner,ProjectLifecycleStepStore,GatewaySetupSteps}.php
  - apps/gateway/app/Actions/*/{Create*InstanceAction,CopyInstanceDependenciesAction,RegisterInstanceAction,RunInstanceSetupAction}.php
  - apps/gateway/app/Infrastructure/{*/NativeDevelopment*Provisioner,Instances/{RemoteDevelopmentInstanceConfigurator,RemoteDevelopmentInstanceSourceLifecycle,RemoteRegistrationSourceManager,RemoteInstanceDestinationGuard,RemoteInstanceDependencyCopier},AppDev/DevelopmentSshExecutor,AppProd/ProductionSshExecutor}.php
  - apps/gateway/app/Domain/Instances/{DevelopmentInstanceProvisioner,InstanceSourceProfileGuard,DependencyCopy/InstanceDependencyCopier}.php
  - apps/gateway/app/Http/Controllers/Api/ProjectLifecycleStepsController.php
  - apps/gateway/{app/Models/ProjectLifecycleStep.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php}
  - apps/agent/src/commands/{ci_caches.sh,dev_source.rs,dev_source_tests.rs}
  - apps/cli/app/Commands/Instances/{SetupInstanceCommand,*SetupStepCommand,*TeardownStepCommand}.php
---

# Instance setup and teardown

A Project stores two ordered lists of named commands: setup steps and teardown steps. Orbit runs the setup list after it activates a new development Instance, and the teardown list before it removes an active development Instance. Setup does not require a Route: an unrouted monorepo `default` becomes `active` and runs the same list. Its setup commands can install dependencies in each subproject without application metadata inspection at the repository root.

Production Instances run neither list. They use [deploy steps](/reference/deployments#deploy-steps). A Project's [development deploy steps](/reference/deployments#development-deploy-steps) are a third, independent list. Setup and teardown do not run that list.

Each step is one database row with a name, a command string, a timeout, and a position. Orbit writes no script into the checkout. The lists belong to the Project, and no Instance keeps a copy. The next run uses the lists as they are at that moment.

## Record a step

Record the steps on the Project before you create an Instance. `--project` selects the Project by its numeric ID.

```bash
orbit instance:setup-step:create install-php --project=4 --command='composer install --no-interaction'
orbit instance:setup-step:create install-js --project=4 --command='vp install' --after=install-php
orbit instance:teardown-step:create drop-sqlite --project=4 --command='rm -f database/database.sqlite'
```

| Command | Result |
| --- | --- |
| `instance:setup-step:create NAME --project=ID --command=COMMAND` | Add a setup step at the end, or at `--before=NAME` or `--after=NAME`. |
| `instance:setup-step:list --project=ID` | List the setup steps in order. |
| `instance:setup-step:update NAME --project=ID` | Change `--command`, `--timeout`, or the position. `--rebalance` changes other steps in the same write. |
| `instance:setup-step:destroy NAME --project=ID` | Remove a setup step. The others keep their order. |
| `instance:teardown-step:*` | The same four commands for the teardown list. |

`destroy` asks for confirmation with No selected. `--yes` confirms without a prompt and is required for JSON and noninteractive calls.

The Gateway checks each change against these limits and stores nothing when one fails.

| Field | Rule |
| --- | --- |
| `name` | 1 to 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. Unique within the list. |
| `command` | Nonempty UTF-8, at most 16 KiB, no NUL byte. |
| `timeout_seconds` | 1 to 540. The default is 240. |
| `before`, `after` | The name of a step in the same list. Use at most one. |
| `rebalance` | Other steps in the same list and their new timeouts, each as `name` and `timeout_seconds`. The Gateway sets them in the same write. |

A list holds at most 32 steps. The timeouts of one list add up to at most 540 seconds, so a whole list fits in one API request. The Gateway stores a step only when its timeout is inside that limit, and a later read returns the stored timeout.

A list stored before that limit can total more. The Gateway keeps it, and accepts a change only when the change does not raise the list's total. A lower total becomes the new ceiling, so do not lower a step on its own to make room.

### Make room in a full list

To add a step to a full list, or to move time from one step to another, change the other steps in the same write. Repeat `--rebalance=NAME=SECONDS` for each step whose timeout changes. The Gateway checks the whole list after the change, so the total must stay within 540 seconds, or within the stored total of an older list.

```bash
orbit instance:setup-step:create browsers --project=4 --command='npx playwright install' --timeout=180 \
  --after=install-js --rebalance=build-assets=360
orbit instance:setup-step:update build-assets --project=4 --timeout=540 --rebalance=install-js=360
```

The first command adds a 180-second step and lowers `build-assets` by 180 seconds. The second gives that time back to `build-assets` and takes it from `install-js`. A write that would raise the total stores nothing and fails with `The lifecycle list timeout total is too large.`

Authorized reads return the commands. [Activity](/cli/activity) records no input for the step commands and `instance:setup`, so it never holds command text or command output.

## Checkout access

The Gateway prepares a development checkout as the Node's managed user. The checkout and the files in it stay owned by that user and group. Every Gateway Git command on the checkout runs as the managed user. The [agent commands](/reference/node-agent#commands) `instances.prepare-development-source`, `instances.inspect-development-source`, and `instances.resolve-development-source` run these steps, and each one first checks that every parent of the checkout is a real directory under the apps root. Production releases are unchanged.

Every Gateway Git call sets `core.hooksPath=/dev/null` and `core.fsmonitor=false`, so a hook path or a filesystem monitor in `.git/config` never runs. A linked worktree is added without a checkout; the branch checkout at resolve writes its files. Caddy can read only the web root and the public storage target; [Caddy access](/reference/routes#caddy-access) describes the walk.

A task workspace is a checkout inside its own [task VM](/reference/compute-drivers#task-vms). There the VM's managed user `orbit` owns it and runs the agents, the setup steps, and the checks.

The Gateway names the Instance's Git directory from its records: `<seed>/.git` for a worktree of a seed, otherwise `<checkout>/.git`. It never asks Git for it, because Git follows `.git/commondir` and a `.git` file in the checkout.

The managed user writes task metadata under `$(git rev-parse --git-path orbit)`. In a linked worktree this is its private administration directory, not a directory under the `.git` pointer file. The Gateway validates both the pointer and its return path, and pins owned directories before publishing metadata.

### Credentialed Git

On a task VM none of this runs: the Gateway fetches and pushes in its own mirror and sends no token to the VM. See [Git on a task VM](/reference/compute-drivers#git-on-a-task-vm). The rest of this section covers development checkouts on other Nodes.

A fetch and `ls-remote` carry a GitHub token. `ls-remote` runs outside the checkout. A fetch runs in the checkout as the managed user, against the Project repository URL with explicit refspecs, never against a configured remote. It sets `credential.helper` empty, `http.followRedirects` to `false`, and `core.alternateRefsCommand` to `true`, besides the hook and fsmonitor overrides above. Only HTTPS may carry the fetch: `protocol.allow` is `never`, and each transport is set on the command line, so `ssh`, `ext`, `file`, and `git` stay off. The token exists only in the environment of that one `git` command. It travels in the command's input on the standard input of the SSH process, never in an argument or a file.

The checkout's `.git/config` is not trusted. The operator writes it, and so does every program that runs as the managed user: setup steps, development Processes, and any agent working in the checkout. Command-line settings outrank that file, so a planted credential helper, hook, `url.*.insteadOf`, or transport setting cannot run a command or move the fetch off HTTPS while the token is in the environment.

## Run setup

A create that fails before activation cleans up its owned resources without running setup or teardown, and keeps the original failure code. If cleanup cannot finish or the process is interrupted, removal accepts the pre-activation states `reserved`, `checkout_prepared`, and `source_resolved` and skips teardown. See [pre-activation removal](/reference/instance-removal#pre-activation-removal).

`instance:create` runs the setup list after the Instance and its Route are active, and after the [database clone](/domains/applications#database-clone) or the [database on a server](/domains/applications#database-on-a-server) when it applies. Setup steps receive two variables:

| Variable | Value |
| --- | --- |
| `ORBIT_SEED_PATH` | The checkout of `default` on the same Node. |
| `ORBIT_SEED_COMMIT` | The last commit that [deployed](/reference/deployments#development-defaults) in that checkout. |

Orbit reads them again before each step. Project steps own the [dependency copy](/domains/applications#dependency-copy), including nested monorepo folders.

When the default has not deployed at source preparation, Orbit records an empty seed decision and setup installs from lock files. Retries keep that decision. When Orbit registers an Instance, it records a seed only if the adopted source commit matches the default's deployed commit. It never pairs an older source with newer seed dependencies.

A task VM has no `default` Instance and no seed. Every new task workspace [clones from the Gateway mirror](/reference/compute-drivers#clone-and-set-up-every-workspace), then runs the Project's setup steps. The read-only host package cache seeds private manager caches before setup; `@orbit/ci-caches` imports the SHA-bound cache ref. Running workspaces keep their existing checkout and disk.

A routed Laravel Instance has its `.env` before setup runs. When Orbit creates that file, it already holds `APP_URL`, a usable `APP_KEY`, and the Project's `APP_NAME`, so setup does not need `key:generate`. See [Laravel application URL](/domains/applications#laravel-application-url). A routed task VM workspace gets that `.env` and its PHP packages before the baseline check, and its Route after the check started. Its setup steps must not need the Route. See [Start the baseline check before the Route](/reference/compute-drivers#start-the-baseline-check-before-the-route).

Activation records `failed_step: setup` in the same transaction, so a Gateway interruption before or during setup cannot make an identical create retry report success without setup. Orbit clears the marker only after setup completes.

`instance:create` also marks a new Instance as unfinished until its first setup completes, through `instance:create` or `instance:setup`. Only that mark lets a create retry finish the Instance's database and then run setup. A live Instance whose later `instance:setup` failed also has `failed_step: setup`, but it lacks the mark: a create retry returns `instance.setup_step_failed` and changes nothing. Only the request that activated an Instance removes it after a failure. A retry that resumes an unfinished create keeps the Instance when the database or a setup step fails, and its error says the Instance remains.

Each command runs with `bash -eu` at the repository root of the code the Instance serves, even when the Laravel [application directory](/reference/projects#application-directory) is nested. For example, a setup command for root `apps/site/public` must use `cd apps/site && composer install` to install that application's dependencies. Teardown and task-check commands also keep their repository-root scope. Each setup command runs on the Instance's Node, as the Node's managed user. This is the `instance:create` and `instance:setup` path. A task workspace does not use it. The task baseline runs the same commands, also as the managed user, inside the task check. [Project check](/reference/tasks#project-check) describes that run.

That root is the checkout. A `default` Instance deploys in its checkout too, so setup sees the code that runs. Its seed is its own checkout, so its setup gets empty seed variables. Setup, teardown, and a [development deployment](/reference/deployments#development-defaults) of the default share the lifecycle lock on the checkout, so they never run at the same time.

Setup and teardown export `VP_HOME` to the Node's [resolved Vite+ store](/reference/tools#tool-managers). Project-local `vp` processes inherit that value even in these non-login shells, rather than using the default `~/.vite-plus`.

The agent's `instances.run-lifecycle-step` runs each step. Commands read no input. Orbit retains only a bounded 1 KiB stderr tail internally; public errors do not include command output. When a step ends, for any reason, Orbit kills its process group, so background processes do not survive the step. Each run holds a lifecycle lock on the Instance: an exclusive `flock` on its checkout directory, so the lock leaves no file behind. If another operation holds that lock during `instance:create`, Orbit keeps the active Instance and records `error_code: instance.lifecycle_busy`.

An identical create retry then reports that setup must run; use `instance:setup` to retry the list. A busy lock never removes the Instance.

The first command that exits non-zero or times out stops the list. Then Orbit rolls back the new Instance:

1. It runs every teardown step.
2. It removes the Instance with forced removal, which also deletes a dirty checkout.
3. It returns `instance.setup_step_failed` with the failed setup step, or `instance.setup_step_unavailable` with `outcome: missing` for a missing or non-executable command (exit 127 or 126).

An unavailable setup error keeps its step, Node, and not-found diagnostic and says the Instance was removed. A failed teardown step is named too.

A teardown failure during this rollback does not keep the Instance. Rollback never removes another Instance.

Orbit keeps the Instance, with its setup marked failed, in three cases:

| Case | Result |
| --- | --- |
| Orbit cannot confirm the setup step's outcome, for example after a lost SSH connection. | No rollback runs. The error is the step's own `instance.setup_step_failed` with `outcome: unconfirmed`. |
| Orbit cannot confirm a teardown step's outcome. | The error adds `cleanup: unconfirmed`. |
| The removal starts but does not finish. | The error adds `cleanup: incomplete` and names `orbit instance:destroy <id> --force`. |

Incomplete or unconfirmed cleanup keeps the setup error's classification and details. When setup was unavailable, the error also keeps its step, Node, and not-found diagnostic alongside the cleanup annotation. Inspect the Instance before you retry.

[Registration](/domains/applications#register-an-existing-checkout) verifies the source's own state, not unrelated refs in its shared repository. In-place adoption records the verified destination before it closes the environment file's permissions. For a nested Laravel root such as `apps/site/public`, that file is `apps/site/.env`; checks for cached Laravel configuration also use `apps/site/bootstrap/cache/config.php`. Git identity keeps its repository-wide scope. A retry after that checkpoint checks the destination's Git identity rather than the pre-adoption digest, so Orbit's own permission change does not prevent recovery. Setup still runs only after adoption completes.

`instance:register` runs no setup. `instance:register --setup` runs the setup list after adoption. `instance:setup` runs the list again on an active development Instance. Both keep the Instance when a command fails and return `instance.setup_step_failed`, or `instance.setup_step_unavailable` when the command is missing or not executable. Every run starts at the first step.

```bash
orbit instance:setup <instance>
```

`instance:clone` runs neither list.

## Deadlines

One API request runs a whole list. The request's remote work ends after 570 seconds, and its forward work stops 20 seconds earlier to leave time for cleanup. Each step's timeout is cut to the time that remains.

`instance:create` keeps 150 seconds back from its setup list for rollback: up to 60 seconds for the teardown list and 90 seconds for the removal. Nested operations, such as rendering a [Gateway-owned setup step](#gateway-owned-setup-steps), keep their own local timeout while preserving that hold and the request cleanup reserve. They cannot extend the parent request deadline. `instance:setup` and `instance:register --setup` roll nothing back, so their setup list can use the whole request.

A step that the request deadline stops, or that has no time left to start, is not a failed command. The request returns `command.deadline_exceeded` (HTTP 504) with `outcome: deadline` and the step name. `instance:create` still rolls back and keeps that code. Lower the step timeouts until the list fits.

## Run teardown

`instance:destroy` of an active development Instance runs the teardown list after the [removal checks](/reference/instance-removal) accept the source. Then Orbit checks the source again and deletes the Route, the source, and the record. In a forced removal of a checkout with worktrees, each member runs its own teardown list.

Teardown may delete ignored files. It must keep the checkout, its Git identity, and its worktrees. When teardown changes tracked files, normal removal refuses. Retry with `--force` to discard them.

The first teardown command that exits non-zero or times out stops the removal. The Route, source, and record stay, and the command returns `instance.teardown_step_failed` with the step name. A missing or non-executable command (exit 127 or 126) returns `instance.teardown_step_unavailable` instead, with `outcome: missing`. The message names the step and its Node and says the command was not found or is not executable. Setup uses the same distinction with `instance.setup_step_unavailable`. Orbit retains a bounded stderr tail internally, without including command output in the public error. Fix or destroy the step, then run `instance:destroy` again.

## Bootstrap the Orbit repository

The Orbit Project can record `bin/bootstrap` as a setup step, or record its locked dependency installs as separate steps. It installs the locked dependencies, seeds caches, and runs the checks in all five Composer projects. A cold bootstrap can exceed the request deadline.

```bash
orbit instance:setup-step:create bootstrap --project=PROJECT_ID --command='bin/bootstrap' --timeout=540
```

`bin/bootstrap` installs the Git driver that merges generated files. If the Orbit Project uses separate dependency steps, the operator must add the following setup step after the generated-merge change is deployed. It runs for new task workspaces before agent turns; Git attributes alone do not install a driver. Re-read the live Project first and preserve its existing steps. See [Merging generated files](/contributor-guide#merging-generated-files).

```bash
orbit instance:setup-step:create generated-merge --project=PROJECT_ID --command='bin/generated-merge --install' --timeout=30
```

Task workspaces are not created with `instance:create`, so this create-time run does not happen for them. The task baseline check runs the Project setup steps before the task check instead. See [Project check](/reference/tasks#project-check) and [Implementation loop](/reference/implementation-loop).

## Beast setup changes after deployment

These are operator steps on shared beast, not task fixtures. Re-read Projects 33 (`orbit-website`) and 46 (`orbit`) and their ordered setup and development deploy lists before changing them. Preserve their Instance-specific environment, key and database setup. Finish initializing Orbit's default Instance 303 without a Route, and deploy the default of each Project successfully before using it as a seed.

### Project 33

Record ordered [development deploy steps](/reference/deployments#development-deploy-steps) for the website's locked Composer and Bun installs, migrations, and asset build. Keep installs, migrations, and builds required. Do not put Instance-only environment, key, or database-copy setup in this list. Read the default Instance's ID from `orbit project:show 33 --json`, then run `orbit instance:deploy ID --json` and check its final result before enabling seed copies.

Add a first setup step that copies the website's `vendor` and `node_modules` from `ORBIT_SEED_PATH` when it is nonempty. Use `cp -a --reflink=auto` into absent destinations. Keep the locked incremental install afterward. An install that deletes the destination first discards the copy.

### Project 46

Record ordered development deploy steps for every locked dependency tree: the repository root, `apps/cli`, `apps/gateway`, `apps/docs`, `apps/web`, `packages/php-sdk`, and `apps/e2e`. Run the Gateway migrations and the builds required by the deployed applications after their installs. Keep these steps required. No step runs a test suite: main CI fills the [main caches](/reference/implementation-loop#main-caches). Preserve Instance-only setup in the setup list. Retry `orbit instance:create 46 9 default --json` to finish the existing SourceResolved Instance 303 without a Route; confirm its identity and active status, then run `orbit instance:deploy 303 --json` and check the final result.

Add a first setup step that copies present `vendor` and `node_modules` folders at the repository root and under `apps/cli`, `apps/gateway`, `apps/docs`, `apps/web`, `packages/php-sdk`, and `apps/e2e`. Pint's `vendor/pint.cache` and PHPStan's `vendor/phpstan/cache` travel with `vendor`. Keep locked installs for every dependency tree as the empty-seed fallback. Never copy `.env`, databases, logs, build runtime files or symlinks that point outside the seed.

### Cache ownership

Create a disposable new Instance to check nested dependency copies. Test the empty-seed fallback separately.

A setup copy step must be retryable: keep an existing destination, stage a missing directory, then rename it into place only when `cp` succeeds. A copy failure fails that step rather than leaving a partial dependency tree. On ZFS, `--reflink=always` can enforce shared blocks; `--reflink=auto` provides the ordinary-copy fallback. Verify shared blocks on beast and write isolation by changing a copied dependency or cache in a disposable Instance and checking that the selected seed stays unchanged. Remove only that disposable Instance after the check.

## Configure Orbit's task policy

Orbit's task check is explicitly `composer check`; new Project defaults do not supply it. Record setup steps for each dependency tree that this repository check needs. The baseline runs only those steps. The live Orbit Project is id 46. Re-read it before every deployment, and stop if the id, check, or lists differ from the handoff.

### Preflight

These reads change nothing. Run them against the Gateway that will receive the deployment.

```bash
orbit project:show 46 --json
orbit instance:setup-step:list --project=46 --json
orbit instance:teardown-step:list --project=46 --json
```

Confirm `task_check` is `composer check`. Confirm each stored `timeout_seconds` is an integer from 1 to 540. A new list totals at most 540 seconds. A list stored before that cap may total more, and a later change may not raise its total. To add a step to such a list, [rebalance the other steps in the same write](#make-room-in-a-full-list).

The task baseline runs each setup step with that step's own timeout, outside the API request deadline. `instance:create`, `instance:setup`, and `instance:destroy` run one whole list inside one request. Remote work ends at 570 seconds, and forward work stops 20 seconds earlier. `instance:create` holds 150 seconds back: 60 for teardown and 90 for removal.

A setup list whose timeouts cannot fit is cut with `command.deadline_exceeded`. Lower those timeouts before relying on `instance:create` or `instance:setup` for this Project. Do not raise the total.

## Gateway-owned setup steps

A setup step whose command is `@orbit/ci-caches` runs a Gateway-owned operation, not a shell command. The Gateway renders its command right before the step runs, within the step's timeout. Development Instance setup and the task baseline setup list run it the same way. Teardown rejects it. Its errors name the step. The check log shows at most the first 4096 characters of a setup command, so an operation's payload does not fill the log.

## Import CI main caches

Add this step after the install steps of a monorepo whose CI publishes main caches the way Orbit's `ci.yml` does:

```bash
orbit instance:setup-step:create ci-caches --project=PROJECT --command='@orbit/ci-caches' --timeout=120 --json
```

CI publishes main's Pint and PHPStan caches at `refs/orbit/ci-cache/<sha>`, where `<sha>` is the full code commit tested on `main`, not the commit holding the cache files. The [CI publication policy](/reference/implementation-loop#ci-cache-refs) defines the publisher, manifest, authentication, and retention. This Git ref replaces the artifact download and base64 setup-command payload. `@orbit/ci-caches` remains the setup operation, and `bin/ci-cache-ref import` validates the cache files obtained from the ref; neither needs a GitHub Actions artifact in the workspace.

For a task VM, the Gateway fetches code and the cache namespace into its [Git mirror](/reference/compute-drivers#git-on-a-task-vm), then transfers the selected objects over SSH. Source objects travel first; an optional cache pack follows, so a failed cache transfer cannot fail the source transfer. Ordinary development Instances keep their existing source fetch path and receive an optional cache-only pack from the same Gateway mirror when their Project declares this setup operation.

For GitHub, it uses its [GitHub App](/reference/github-app) with `Contents: read` for this repository, using the existing credentialed Git protections. Reading cache refs needs no `Actions: read` permission. The cache transfer gives the workspace no GitHub credential. Task VMs do not fetch from GitHub themselves. Cache objects stay outside the source tree until the setup step imports them after dependency installation.

[New task workspace selection](/reference/implementation-loop#ci-cache-refs) separates the starting code commit from the cache baseline before setup.

The Gateway selects a code SHA before looking up its cache ref. For a checkout of `main`, that is the selected checkout commit. For a task branch, it is the recorded baseline commit from `main`, which must be an ancestor of the checkout's `HEAD`. Another branch with no recorded baseline whose `HEAD` is a `main` commit, such as a fresh agent branch, selects that `HEAD` commit; without one, it runs cold. It requests only `refs/orbit/ci-cache/<selected-sha>`: it never substitutes the newest cache ref, a different ancestor's ref, or a ref for a later main commit.

The Gateway records the task baseline in `refs/worktree/orbit/ci-baseline`, a per-worktree ref that other linked workspaces cannot overwrite. Later main fetches preserve it while a task branch is checked out. The ref suffix and every manifest's tested commit must agree exactly. The cache commit's own object ID is not the tested code SHA.

The importer checks each project's identity, manifest schema, file checksums, lock file, tool configuration, and PHP minor version before writing private caches. `bin/ci-cache-ref import` validates the whole cache tree, then imports compatible Pint and PHPStan caches into their tool cache paths under `vendor/`. The ref holds no test graph. Rector exports no cache because its checks use `--clear-cache`. An absent optional tool cache leaves that tool cold.

The importer also checks the PHP version that each quality cache records, because each tool discards a cache from another PHP version:

| Cache | Must match the workspace |
| --- | --- |
| Pint | The full PHP version that the cache records under `php`, such as `8.5.11` |
| PHPStan | The PHP version that the result cache metadata records, such as `80511` for 8.5.11 |

PHPStan also compares the loaded PHP extensions and their versions. The importer does not check those, so after a PHPStan import the log says `[<project>] imported CI phpstan cache; PHPStan may still run cold if the PHP build differs`. When a cache does not match, or records no PHP version, the log says `[<project>] <tool> cache not imported, it runs cold: <reason>`, and the tool runs cold.

Cache files must be regular files at allowed relative paths, with no symlinks or path traversal. The payload is limited to 1,024 files and 64 MiB of expanded data; temporary import files are removed when the step exits.

PHPStan's result cache is PHP code, which PHPStan executes when it reads the cache. Orbit trusts it only because the repository's own successful main CI publishes the ref. Pull request and fork runs cannot publish workspace cache refs. The default branch, workflow, and users who can write repository refs must therefore be trusted to run code in the checkout. Checksums detect damaged bytes, not a malicious publisher. Matching SHAs and configuration guard compatibility; Git transport does not make an untrusted cache safe.

A retry or later baseline preserves the workspace's own quality caches: the importer writes a cache only where none exists. The importer still checks that the ref and payload match the selected SHA. The log names each cache it keeps or skips and the reason.

Cache availability does not gate setup or baseline checks. The code fetch remains required; the cache fetch is optional and must not turn an absent ref into a failed code fetch.

| Situation | Result |
| --- | --- |
| Code fetch or source authentication fails | The source operation fails; a cold cache cannot repair missing code |
| Cache ref is missing, not yet published, pruned, or cannot be read | Setup continues without imported caches and logs the reason |
| Ref suffix, manifest, or selected baseline disagree; baseline is not an ancestor of `HEAD` | Reject the payload and continue cold; never use another ref |
| Payload exceeds the limits, has unsafe paths, invalid data, or bad checksums | Reject it before writing caches, log the reason, and continue cold |
| A quality cache's lock file, tool configuration, or PHP version differs | Skip that tool cache and log the reason |
| Required import tooling is missing or writing a validated cache fails | Fail the named setup step; this is a broken setup, not a cache miss |

An older ref is usable only for its explicitly selected baseline, never as a fallback for another SHA. A workspace created before CI publishes its baseline ref, or whose baseline ref a newer publication pruned, runs cold. A later source refresh followed by setup or a task baseline can import that same SHA's ref. [Ref retention](/reference/implementation-loop#cache-ref-retention) does not remove caches already imported into a running workspace. The [shared package cache](/reference/compute-drivers#shared-package-cache) accelerates dependency installs separately; workspaces always clone and run setup.

## Failure codes

These codes name the step that failed. The sections above say whether the Instance stays.

| Code | Cause |
| --- | --- |
| `instance.setup_step_failed` | A setup command failed or timed out. |
| `instance.teardown_step_failed` | A teardown command failed or timed out during `instance:destroy`. |
| `instance.setup_step_unavailable` | A setup command was not found or is not executable on the Node; `outcome: missing`. |
| `instance.teardown_step_unavailable` | A teardown command was not found or is not executable on the Node; `outcome: missing`. |
| `instance.setup_unavailable` | `instance:setup` targets an Instance that is not an active development Instance. |
| `command.deadline_exceeded` | The request deadline stopped a step. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Named rows, not a script

Each command is its own row, so a failure names one step and you can reorder steps without rewriting a script. One script body and a path in the repository were rejected: the failure report could name no step, and the operator records the command on the Project.

### Lists on the Project

The commands run for every Instance of a repository, so they live on the Project. Per-Instance copies were rejected because they would drift from what the operator recorded. The command family is `instance:` because the commands run for an Instance.

### A failed setup removes the new Instance

A half-set-up Instance is not a useful result of `instance:create`. So a confirmed failure tears it down and removes it, and the next create starts clean. Resuming a create after a failed setup was rejected.

### Registration skips setup by default

Registration adopts a checkout that is usually set up already. Running setup on every registration was rejected. `--setup` runs it on request.

### Teardown failure stops removal

Teardown is the operator's cleanup. Removal continues only after that cleanup succeeds.

### Task setup runs in the task check

Create-time setup runs as the managed user, before an agent uses the Instance. A task workspace skips that path. Its baseline runs the setup list inside the task check on the task VM, as the VM's managed user `orbit`, which also runs the agents. That user has sudo, so host-dependent setup and tests work. [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) explains why the VM needs no second user. Task teardown runs as the same user before the Gateway destroys the VM.

### Gateway-owned baseline delivery

CI artifacts are repository data. The Gateway already owns the GitHub App, so it downloads and validates the files before Node delivery. Personal tokens on disposable machines were rejected because they duplicate credentials and complicate revocation. A fixed setup identifier keeps the existing ordered step contract and prevents Projects from selecting arbitrary artifact URLs or granting a Node App access. Both instance setup and task baseline setup interpret it before any shell execution.
