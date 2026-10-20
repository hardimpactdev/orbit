---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/{AdrLifecycle,DocsImpact}.php
  - bin/{review-check,project-vocabulary,bug-repro,task-group-check,pr-head-check,deploy-verify,test,tia-cache,ci-cache-ref,check-pest-shards,worktree-cache,worktree-create,worktree-remove,check-classification-fakes,check-processes,quality-scope,php-build}
  - tools/{check-processes,rector-cache}.php
  - tools/check_budget.py
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/setup.sh}
  - .github/{workflows/*.yml,actions/php/action.yml}
  - apps/gateway/{phpunit.xml,tests/Support/TestDatabaseEnvironment.php,tests/Support/TestDatabaseGuard.php,tests/Unit/Configuration/TestTimeLimitTest.php,tests/Feature/Domain/TaskWorkspaceTmpdirTest.php,tests/Feature/Domain/ScheduleValidationTest.php,tests/Feature/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycleTest.php}
---

# Feature delivery

A feature reaches main as one complete pull request with its code, tests, documentation, and any in-progress ADRs. The [contributor guide](/contributor-guide) describes the steps. Orbit builds its own features as [tasks](/reference/tasks): the first subtask writes the documentation, each subtask handoff runs the Project task check, and the task ends in a pull request. This page covers review evidence, merge, CI, the local checks, and the main caches that make those checks fast.

## Review on Incus

Orbit's independent reviewer checks the complete change and reproduces the feature on an [Incus topology](/reference/incus-topologies) before merge. Use the machines allocated to the review, and verify which source commit runs there. Exercise the feature and its important failure cases, then inspect the resulting state changes.

The review records enough evidence for another maintainer to assess the result.

| Evidence | Content |
| --- | --- |
| Revision | The exact pull request head and the source revision that ran on the machines |
| Environment | Node roles, operating system, configuration, and resource identity |
| Acceptance | Actions, expected and observed outcomes, exit codes, and state changes |
| CI | A passing `Required checks` job for the reviewed change |
| Limitations | Unverified behavior, failed checks, and open findings |
| Verdict | Whether the complete feature is ready for maintainer approval |

Store detailed logs in CI artifacts or in shared evidence outside the checkout, and link them from the review. Share only sanitized records. When no machines are available, the reviewer returns code findings and marks the Incus review as pending. For documentation and tooling changes, verify the behavior that the change affects.

## Merge and cleanup

Address the blocking findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. The final review must cover the commit proposed for merge.

A merge needs a complete feature, passing CI, a successful independent code and Incus review, resolved blocking findings, and maintainer approval. Merge the approved commit and verify the result on GitHub. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

### Final review of an Orbit task pull request

A Project with [review and merge](/reference/tasks#review-and-merge) on does not use this workflow. Orbit's final review, merge check, and App merge replace it. The workflow below applies to every other Project.

When the maintainer delegates final review and merge of a named Orbit task pull request, that delegation is the consent for that work only. The DevOps reviewer uses the maintainer's GitHub CLI profile. The reviewer checks the whole pull request, confirms the independent code and Incus review evidence, and submits a formal GitHub review for the exact head.

The review body names the full head commit SHA, the checks and their results, any remaining limitations, links to the review evidence, and the verdict. A limitation that leaves required behavior unverified prevents approval. A Tasks engine subtask approval does not replace this final review of the whole pull request.

The merge gate requires a formal GitHub `APPROVED` review from the designated final reviewer for the current head. Submit `APPROVE` through the GitHub reviews API from the maintainer profile, with `commit_id` set to the reviewed SHA. A plain comment, including a ready-to-merge verdict, does not satisfy the gate. The pull request author cannot approve their own pull request. The delegation does not authorize an unrelated merge.

Use [`bin/pr-head-check`](/reference/delivery-line#binpr-head-check) to check for an `APPROVED` review and successful `Required checks` on the exact current head, and for named leftovers in the diff. A `COMMENTED` review on that head does not pass. The command does not verify the designated reviewer's identity or merge the pull request.

Before the immediate merge, read GitHub's review records and verify the final reviewer's identity, the `APPROVED` state, and that `commit_id` matches the reviewed SHA. A dismissed or stale approval, an approval for another head, outstanding requested changes from the final reviewer, the wrong identity, or unreadable review data prevents the merge.

The reviewer also confirms that `Required checks` succeeded on that same head, that blocking findings are resolved, that required verification is complete, and that the pull request head still matches the reviewed SHA. The reviewer then runs `gh pr merge <pr-url> --merge --match-head-commit <reviewed-sha>` from the maintainer profile. If the head changes, stop. Review the new commit, repeat the affected checks, and submit a new formal approval before trying again. A failed, pending, missing, or unreadable required check prevents the merge.

This workflow merges immediately after those checks pass. It does not enable GitHub auto-merge. After the merge, verify the merged state and record the merge commit. The Tasks scheduler observes the merged pull request and completes the task on its next tick.

The maintainer profile is an admin profile. It bypasses GitHub enforcement of the `Required checks` status rule, including on `gh pr merge`. GitHub does not require this approval and does not enforce it for that account, so the reviewer checks the formal approval and the successful `Required checks` result before invoking the merge. This workflow keeps that bypass and does not change the ruleset. It adds no `tasks:merge` command, Gateway merge endpoint, SDK contract, MCP contract, or API contract, and it changes no App permission.

Final DevOps review and its designated reviewer stay outside Orbit. The Tasks scheduler also consumes [trusted GitHub requested changes](/reference/tasks#trusted-github-feedback) as bounded fixup input. The Gateway operator's repository-scoped account allowlist is repair authority only; it does not designate the final reviewer or enforce this merge workflow. `COMMENTED` reviews remain informational, and an observed `APPROVED` review neither completes the task nor authorizes a Gateway merge.

Operators can [inspect durable approval evidence](/reference/tasks#inspect-approval-observations) through the read-only Gateway console report. Its reviewer/review/commit provenance and `current`, `historical`, or `unverified` status describe the stored scan only. It cannot replace this workflow's fresh checks or establish the designated final reviewer's identity or delegated consent.

For blocking findings, submit a formal `REQUEST_CHANGES` review through GitHub's reviews API with `commit_id` set to the full reviewed head SHA. Put the bounded findings in its body and inline review comments, not only in an issue comment or linked evidence. An eligible trusted request creates at most one automatic fixup for that review ID, within the shared [fixup caps](/reference/tasks#fix-a-settling-pull-request). Editing or dismissing a consumed review does not rewrite or cancel that work.

If the request exceeds the retrieval limits or needs a product decision, scope it with the operator instead of assuming Orbit will follow links or implement every instruction in the prose.

A fixup's fresh internal reviewer checks its snapshotted findings and the Project checks. Its push updates the same pull request. It does not submit a GitHub decision, comment, dismissal, or re-review request. The external reviewer must review the new head and submit a new formal decision. An old request or approval is stale, even if the fixup seems small.

Read all effective decisions from the designated final reviewer in submission order, with review ID breaking ties: a later `COMMENTED` review does not erase an approval or requested changes, and dismissal does not revive an older decision. The final reviewer still checks the complete PR, independent evidence, resolved findings, and successful `Required checks` on the exact head before the authorized immediate merge. Orbit observes the merge and completes the task afterward.

After the merge, keep the review evidence and release the resources allocated to the feature. For a local worktree, run `bin/worktree-remove ISSUE`.

[Delivery-line proofs](/reference/delivery-line) are the read-only commands for reproduction on current main, task-group shape, the current pull-request head, and post-merge live state. They do not file a task, merge, deploy, or roll back. Use `bin/pr-head-check` to confirm the diff adds no named leftover, including `object-storage-host` and `linear-reference`; the [leftover list](/reference/delivery-line#binpr-head-check) defines their generic, public-safe forms.

## CI

CI passes its job-scoped, read-only `GITHUB_TOKEN` to Composer through workflow-level `COMPOSER_AUTH`. GitHub API requests use `github-oauth`; `codeload.github.com` uses bearer authentication too, because Composer does not send the API OAuth header to that host. The token stays in the workflow environment and is not written to an auth file.

GitHub CI runs on every pull request, on every push to `main`, every night on `main`, and on manual dispatch. It has these jobs.

| Job | Checks |
| --- | --- |
| One job per Composer project: CLI, Docs, Gateway, E2E, PHP SDK | `composer validate --strict`, `composer check`, the classification-fakes check, and the full tests. On PRs, Gateway runs quality checks and the shard-union check here; its tests run in the four shard jobs |
| Gateway shards (1), (2), (3), (4) | Every pull request: four stock Pest shards of the full non-privileged Gateway suite, independent of the quality job |
| API reference | `bin/project-vocabulary`, `bin/env-docs`, `bin/tooling-baseline`, `bin/docs-nav`, `bin/docs-openapi --check`, and `bin/mcp-tools --check` |
| Web | Generated API types, formatting, lint, types, separate `bun run test:unit` and `bun run test:browser` steps, and build. A run on `main` also publishes the build |
| Pi server | Formatting, lint, types, tests, and build |
| Rust agent | `cargo fmt`, `cargo clippy`, tests, and static builds for x86_64 and aarch64, with Cargo caches. A pull request that changes neither `apps/agent` nor `ci.yml` skips these steps |
| Remote programs | Installs shellcheck, ruff 0.17.0, and the pinned pytest requirements, then runs `bin/remote-programs-check`: the lint and direct tests of the Gateway's [remote programs](/reference/remote-programs#lint-and-direct-tests) |
| Desktop app | `bun install --frozen-lockfile`, then `cargo fmt` and `cargo clippy` on the Tauri shell with the WebKitGTK libraries installed. A pull request that changes neither `apps/desktop` nor `ci.yml` skips these steps |
| Required checks | Passes only when every other job passes, as described below |

Gateway project and shard jobs supply Bun 1.4.2 alongside Node/npm. The package-cache integration tests run real npm and Bun installs against seeded private caches and a local test registry.

The Docs project job also supplies Bun 1.4.2 and installs the Gateway's Composer dependencies and the web app's locked Bun dependencies. Its [proof for merging generated files](/contributor-guide#merging-generated-files) runs the real generators in disposable clones, including in each full suite.

### Action pins and token permissions

External actions use full commit SHAs, with the reviewed version in a trailing comment. Dependabot's `github-actions` entry updates these pins. Local actions and reusable workflows keep their repository paths.

Workflows default to `contents: read`. Read-only jobs set `persist-credentials: false` on every checkout. Write permissions belong only to publishing jobs that never run pull-request code: the main CI cache publisher, the CLI release publisher, the recipe artifact mirror, the Git update issue publisher, and the agent release publisher.

Git build watch checks and uploads its report with a read-only token. A separate job downloads that run's report and alone has `issues: write`; it runs only on main's scheduled or manual executions, never on pull requests. Agent compilation and tag validation also use a read-only token. The agent publish job alone has `contents: write`, downloads the build job's assets, and creates the release without checking out or executing the compiled code.

GitHub's repository SHA-pinning policy and Dependabot security updates must be enabled separately by an operator. Workflow changes do not change repository settings.

The Web job runs directly on the GitHub-hosted Ubuntu runner, without a job container. After Bun setup and dependency installation, `bunx playwright install --with-deps chromium webkit` installs both engines and their system libraries before the tests. `bun run test:unit` includes Node tests and real daemon verification in Chromium and WebKit. `bun run test:browser` tests the app in Chromium. Each step reports its own time.

### One PHP build in CI

Every job that runs PHP uses the recipe's [PHP build](/reference/compute-drivers#php-build) of version `php.ci`, through the local action `.github/actions/php`. That action runs [`bin/php-build`](https://github.com/nckrtl/orbit/blob/main/bin/php-build), which reads the recipe. So CI and the workspaces that import its [cache refs](#ci-cache-refs) run the same PHP version and the same loaded extensions.

On a GitHub-hosted runner the action runs these steps:

1. It restores `/var/cache/orbit/php-packages` from `actions/cache`, keyed on `bin/php-build key`, the digest of the PHP pins of the runner's architecture.
2. `sudo bin/php-build install --profile=ci-runner --ci --purge-extras` runs [`php.install-build`](/reference/compute-drivers#install-the-php-build), the installer that Nodes and images run too.
3. A hosted runner image ships other PHP packages and the `ondrej/php` source. `--purge-extras` removes them, and only CI machines use it.
4. `--ci` sets `zend.exception_ignore_args=0` for the CLI. The action installs Composer 2.10.3 from a pinned `composer.phar` at `/usr/local/bin/composer`.
5. A job with input `coverage: none` keeps PCOV loaded and sets `pcov.enabled=0`. The job collects no coverage and still loads the recipe's extensions.
6. `bin/php-build verify --profile=ci-runner` fails unless `PHP_VERSION`, each package version, and `php -m` match the recipe.

A self-hosted runner never installs packages in a job. The action only runs `bin/php-build verify --profile=ci-runner`. It fails with the expected and the found build, and names the step that fixes the host. After a PHP bump, update each runner while it is idle:

- Sabre is a roleless Orbit Node. On the Gateway, run `php artisan node:converge-recipe sabre --profile=ci-runner`. The recipe's [`ci-runner` profile](/reference/compute-drivers#role-and-base-tool-sets) installs the build and purges PHP packages outside the recipe. No other profile purges.
- The first run records the profile. Then nightly recipe maintenance converges Sabre, and a manual run needs only `php artisan node:converge-recipe sabre`.
- On Beast and Shark, run `tools/ci-runners/setup.sh container NAME` for each shard container.

#### Merge a PHP build change

A pull request that changes the PHP build runs its CI on the self-hosted runners too, and they only verify. So the runners must hold the new build before that CI runs. With the maintainer's approval, an operator runs from the pull request branch:

1. On Sabre: `sudo bin/php-build install --profile=ci-runner --ci --purge-extras`.
2. On Beast and Shark: `tools/ci-runners/setup.sh container NAME` for each shard container.

Then the pull request's CI runs, and `main` stays green after the merge. After the merge, converge Sabre with the `ci-runner` profile so that Orbit manages it.

[Nightly recipe maintenance](/reference/compute-drivers#bump-the-php-build) opens a pull request when Sury publishes a newer build, and converges Sabre in its overnight service tier after merge. `setup-php` is not used. It cannot pin a patch release, and a hosted runner gets a new patch on its own schedule. It also loads about 26 extensions that Orbit does not use, such as `amqp`, `apcu`, `mongodb`, and `soap`.

`Required checks` requires the project matrix, including Docs, on every event. It also requires every Gateway shard to succeed on pull requests. The PR-only shard jobs must be skipped on main pushes, nightly runs and manual runs.

The [Feature delivery merge gate](#full-suites-gate-merges-tia-serves-the-task-loop) defines the required PR test coverage. Stock Pest sharding divides Gateway tests among four shards, excluding the privileged group. A union check verifies that no test class is missing. The required privileged job runs every privileged test.

Full suites include architecture and subprocess tests. CI has no subprocess-only lanes or affected-test selector. A test that starts PHP still declares `pest()->group('subprocess')` so the coverage limitation stays explicit: runtime coverage cannot link the child process's code to its parent test. The workflow contract lists each project's full suite and subprocess group with Pest and requires every subprocess case to be present in the full listing.

The E2E contract `SubprocessTestGroupTest` fails when a test names `PHP_BINARY` or `PhpExecutableFinder`, or starts a `php` or `composer` command, without the group. It also follows a test helper under `tests/` that does so to the tests that use it.

Every main push, scheduled run and manual dispatch runs each Composer project's full suite with `pest --parallel --tia --fresh`. The fork records a fresh graph while running every test; no restored graph selects CI tests. After a passing run on main, the export requires that graph to record the tested SHA and a result for every linked test file. The job saves the graph and quality caches for [task workspaces](#ci-cache-refs). Local task checks retain TIA, as the [contributor guide](/contributor-guide#3-implement-and-verify) describes.

A push to a `task-*-handoff` branch is a task's [handoff commit](/reference/tasks#handoff-ci). CI runs it as it runs a pull request, with four Gateway shards and no cache saves.

A new push to a pull request, or to a handoff branch, cancels the older run of that pull request or branch. Pushes to `main` share one concurrency group and never cancel a running run. GitHub keeps one running and one waiting run per group, and a newer push replaces the waiting one. So when several merges land close together, the newest `main` commit is tested next. The commits in between get a cancelled run instead of a result.

The newest main run tests every project in full, including every change in between. [Automatic Gateway releases](/reference/gateway-recovery#automatic-releases) deploy the newest `main` commit with a successful `Required checks` result and skip commits without one, so the release includes the skipped commits. Scheduled and manual full runs have a group per branch and commit, so a push never cancels one.

Every checkout in the run uses `${{ github.sha }}`. On a PR this is the event's merge commit, not a moving `refs/pull/N/merge` lookup. Main and reruns also test that immutable event commit. No job checks out a branch and resets it later.

On `main`, the Web job uploads `apps/web/dist` as the workflow artifact `web-dist-<commit>`, named with the full 40-character commit SHA, and keeps it for 14 days. A manual dispatch and the nightly run on `main` upload it too, so every successful `Required checks` run on `main` comes with the web build of its commit. Automatic releases install only the build of a push or a manual run. The artifact holds the contents of `dist` at its root, so `index.html` and `version.json` are at the top level. The Web job fails when the build lacks either file; open pages read `version.json` to find a newer release ([Updates to open pages](/reference/web-app#updates-to-open-pages)).

The nightly run tests the newest `main` commit again, with every project's full suite. It adds a `Required checks` run to that commit. An automatic release needs every such run to pass, so a failed nightly run keeps that commit from shipping. Every later push also runs full suites, so it ships only when the failure is fixed.

The upload includes hidden files and fails when the build produced nothing. The build runs from a clean checkout with no `VITE_*` variables, as [`bin/web-deploy`](/reference/web-app#release-a-build) builds a release. Pull requests and runs on other branches publish no web build. Successful project jobs on `main` also publish the [test baselines](#ci-artifacts).

On `main`, GitHub enforces three rules. The branch cannot be deleted, and it accepts no force pushes, with no bypass. A change to `main` also needs a passing `Required checks` status from GitHub Actions. The branch does not have to be up to date first, so the merge rules in [Merge and cleanup](#merge-and-cleanup) still check the merged result. GitHub requires no review.

Repository admins bypass the status rule automatically, so the maintainer can push straight to `main`. The bypass also applies to `gh pr merge` from an admin account, with or without `--admin`. An admin who merges must first wait until `Required checks` passes on the pull request's head commit. The [Feature delivery merge gate](#full-suites-gate-merges-tia-serves-the-task-loop) defines the PR test boundary.

Each Composer project job checks out full history and follows the [Feature delivery merge gate](#full-suites-gate-merges-tia-serves-the-task-loop).

The Docs job's `composer check` also runs `composer docs-lint`. The E2E job runs `bin/bootstrap --skip-checks` to install every project, because its integration tests use the other projects. The Gateway job installs the Linux tools that the Gateway tests need and creates the `caddy` user. That step stops after 10 minutes, and apt retries a mirror that does not answer within 30 seconds.

The Gateway job also runs `tests/Feature/Logs/RedactionCasesTest.php` in one PHP process with PCRE JIT disabled, independently of TIA and shard selection. The test checks that PHP received the step's `PCRE_JIT` setting. This step runs even if an earlier test step fails and keeps the large-value redaction regressions in `Required checks`.

Docs quality checks, including Docs lint and the ADR lifecycle rules, and the full Docs test suite run once in the project matrix. Like the other project lanes, Docs checks out the immutable event commit, `github.sha`: the merge commit on pull requests. There is no second Docs merge-ref job.

Hosted jobs run on `ubuntu-26.04`, the Ubuntu release that Nodes run, and use the same uutils coreutils as a Node.

### Gateway shards

Each PR runs a static matrix `[1, 2, 3, 4]` with `vendor/bin/pest --parallel --processes=4 --no-tia --exclude-group=privileged --shard=N/4`. The required Gateway privileged job runs every privileged case. The Gateway quality job runs independently, so quality checks overlap all four shards. No planner, plan artifact, API polling or hand-built class filter is involved.

Stock Pest reads the committed `apps/gateway/tests/.pest/shards.json` for time balancing. Refresh it with a real full run from `apps/gateway`: `php -d auto_prepend_file=tests/bootstrap.php vendor/bin/pest --parallel --processes=4 --no-tia --update-shards`, then commit the changed file in a PR. Loading the test bootstrap first gives Pest's parent and workers the same temporary directory for timing files. CI never restores this file from Actions cache or writes it back. All shards therefore read the same timings from the tested commit, including on a partial rerun. Pest distributes new classes even before timings are refreshed.

The Gateway quality job runs `bin/check-pest-shards`. It uses Pest's own `--list-tests` output for the full non-privileged suite and each of the four stock shards. It requires their union to equal the full listing and rejects duplicate assignments. A class that lands in no shard fails CI. Architecture and subprocess tests are part of this listing too.

Runs on main never shard. Each keeps one full Gateway recording run, because Pest writes one whole TIA graph per run. That graph and the exported cache artifact must describe the tested commit.

### Self-hosted Gateway runner

When the repository variable `ORBIT_SABRE_RUNNER` is `true`, the Gateway job and the Gateway shards run on self-hosted runners. On PRs and handoff pushes, the Gateway quality job and each of the four shards take any runner with the labels `self-hosted` and `orbit-shard`, Sabre included. Main, scheduled, and manual runs use one runner with the labels `self-hosted` and `sabre` for the full Gateway suite, because its six workers do not fit a [shard runner](#self-hosted-shard-runners) container with four CPUs and 8 GiB. The Gateway privileged job always runs on a GitHub-hosted runner.

Pushes, manual dispatches, and pull requests from branches in this repository use them. A pull request from a fork always uses GitHub-hosted runners, so code from outside the repository never runs on a self-hosted runner. Set the variable to anything else to move these jobs back to GitHub-hosted runners.

On Sabre the job skips the Homebrew and system package steps, because Sabre already has Caddy, `acl`, `attr`, and `wireguard-tools`. Its PHP step only [verifies](#one-php-build-in-ci) that Sabre runs the recipe's PHP build. Its PHP CLI sets `zend.exception_ignore_args=0` in `99-github-actions.ini`. On a shard runner container, the PR quality job keeps the PHP CLI default for that setting, because none of its steps needs `0`.

A job never installs software on Sabre: the former PHP setup action made `/usr/local/bin` world-writable on a self-hosted runner, and the program that configures service metrics refuses a Node with such a directory. Its `/usr/bin/composer` is Composer 2.10, installed over the Ubuntu package with a `dpkg-divert`, because Composer 2.9 rejects the GitHub Actions token that the former PHP setup action exported. Pest runs 6 processes there instead of 4.

The job installs Node 24 with the Node setup step on Sabre, and tests run the `node` on PATH. On Sabre the runner user has traverse-only access to the managed user's home (`setfacl -m u:github-runner:x`).

Sabre is an Orbit Node with no role and serves no Instance. Orbit manages its PHP through the `ci-runner` recipe profile. Two runner services, `sabre-1` and `sabre-2`, run as the `github-runner` user, so several pull request runs and a `main` run do not wait for each other. That user has passwordless `sudo`. Each runner service mounts its own work directory at `/home/runner/work`, the path that GitHub-hosted runners use. PHPStan and Rector key their caches on absolute paths, so the caches saved by either kind of runner stay valid on the other.

The `github-runner-egress` systemd unit loads an nftables rule that rejects traffic from `github-runner` to private addresses: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, and `100.64.0.0/10`. Orbit trusts WireGuard source addresses, so this rule keeps a job from reaching the Gateway or another Node as Sabre. DNS still works through the local resolver. A command that a job runs with `sudo` runs as root, and the rule does not cover it. So the runner accepts only code from this repository.

### Self-hosted shard runners

The four Gateway PR shards use the `self-hosted` and `orbit-shard` labels. Both `sabre-1` and `sabre-2` carry `orbit-shard`, as do `ci-beast-1` and `ci-beast-2` on Beast and `ci-shark-1` on Shark. The container runners do not carry `sabre`. The workflow selects runners by label, not by name; shards are not restricted to Beast and Shark.

Beast and Shark are app-dev Nodes with live Instances and task workspaces. So each runner runs inside an unprivileged Incus container, not on the host. [`tools/ci-runners/setup.sh`](https://github.com/nckrtl/orbit/blob/main/tools/ci-runners/setup.sh) creates every part and repairs its configuration, and each of its steps is idempotent:

- `host SUBNET` creates the Incus project `orbit-ci`, the bridge `orbitci0`, the network ACL `orbit-ci-egress`, the project's default profile, and a `ufw route allow in on orbitci0` rule. Beast uses `10.253.1.1/24` and Shark `10.253.2.1/24`.
- `container NAME` launches the container, sets its `raw.apparmor` line, and installs what is missing.
- `register NAME [TOKEN]` registers the runner and starts its service.
- `verify NAME` probes, as the runner user, the host addresses and ports that a job must not reach.

`container` upgrades no installed package, except the PHP build, which it brings to the recipe. Run it only while the runner is idle, because a repaired `raw.apparmor` line applies at the next restart.

The registration token comes from `ORBIT_RUNNER_TOKEN` or the argument, and the script never stores or prints it. `config.sh` takes the token as an argument, so the token shows in the host's process list for a few seconds.

The project is restricted: containers stay unprivileged, nesting is blocked, virtual machines are refused, and the only network is `orbitci0`. The profile gives each container 4 CPUs, 8 GiB of memory, and 8192 processes, with no devices beyond its network card and root disk.

Ubuntu 26.04's systemd hangs at boot in an unprivileged container without nesting. AppArmor denies its PID 1 and udevd the signals and traces to their own processes, whose labels are stacked. So each container sets one `raw.apparmor` line: `signal peer=@{profile_name}//&*, ptrace peer=@{profile_name}//&*,`. It allows signals and traces only to processes that carry the container's own profile, so it reaches nothing outside the container. Nesting stays off. The project allows container low-level keys only for this line; only `incus-admin` members can set them.

The ACL rejects every egress to private, CGNAT, loopback, link-local, multicast, and reserved IPv4 ranges, and all IPv6. Its default for egress and ingress is reject. So a job reaches the public internet, but not the host's LAN, WireGuard, the Gateway, docker and task bridges, the other container, or the bridge gateway. A container's `127.0.0.1` is its own loopback, so the host's loopback services, such as the Caddy admin API, are out of reach.

Inside each container, the `runner` user runs the runner service, with no `sudo` and no group beyond its own. So a shard leaves out the `privileged` group. The container has the recipe's [PHP build](#one-php-build-in-ci), installed by `bin/php-build install --profile=ci-runner`, Composer 2, Caddy from its own apt source with its service disabled, `acl`, `attr`, `wireguard-tools`, `dig`, Git, and Python 3. `container NAME` copies `bin/php-build`, the recipe, and the remote program tree that `bin/php-build` reads into the container, and runs that install. A later run replaces the copy and updates the build in place. Composer is at `/usr/local/bin/composer`, with a link at `/usr/bin/composer`, because the Composer update program and its tests run that path, as on a Node.

The job installs Node 24 with the Node setup step, and each shard runs at most 4 Pest processes. The container's PHP CLI keeps `zend.exception_ignore_args` on, so the job turns it off only for its own processes: it writes an ini file into the runner's temporary directory and adds it to `PHP_INI_SCAN_DIR`.

Two limits remain:

- The runners are not ephemeral. A job can leave files or processes in its container for the next job. The container is the boundary, and only approved code from this repository runs there.
- Incus accepts DNS on port 53 from `orbitci0` to every host address, before the ACL applies. Closing it needs a host firewall rule.

So a job can query each dnsmasq on the host, such as the task bridge's. The answers reveal task names and addresses that the job still cannot reach, and a query changes nothing.

To remove the runners from a host, stop and uninstall each runner service as root in its container, and run `config.sh remove` as `runner` with a remove token. Then delete the containers, the project, the bridge, the ACL, and the ufw rule:

```bash
token=$(gh api -X POST repos/nckrtl/orbit/actions/runners/remove-token --jq .token)
for name in ci-beast-1 ci-beast-2; do  # ci-shark-1 on Shark
  incus exec "$name" --project orbit-ci --cwd /home/runner/actions-runner -- sh -c './svc.sh stop && ./svc.sh uninstall'
  printf '%s\n' "$token" | incus exec "$name" --project orbit-ci --cwd /home/runner/actions-runner -- \
    su runner -c 'IFS= read -r token; ./config.sh remove --token "$token"'
  incus delete --force "$name" --project orbit-ci
done
incus image list --project orbit-ci -f csv -c f | xargs -r -n1 incus image delete --project orbit-ci
incus project delete orbit-ci
incus network delete orbitci0
incus network acl delete orbit-ci-egress
sudo ufw route delete allow in on orbitci0
```

Docs-lint also checks the ADR lifecycle. A row in the decisions overview's lower table has no file with its recorded slug, and a redirect exists from that exact path. ADRs from 0180 onward have an `In progress.` Status and a `Principle:` line. A lower number follows those rules only when that lower table does not list its number. The open gaps are 0007 and 0020. The committed allowlist of older live ADRs can only shrink. The [contributor guide](/contributor-guide#checks-that-need-no-network) explains these checks.

Dependency downloads use stock `actions/cache`. The shared PHP setup caches only Composer's download directory, keyed by runner OS, architecture, Composer version, and all Composer lockfiles. Bun jobs cache only `~/.bun/install/cache`, keyed by OS, architecture, Bun version, and all Bun lockfiles. Only non-PR runs on `refs/heads/main` write these entries; all other runs restore without saving. Installs still run with the lockfile in each job. Jobs never share installed `vendor/` or `node_modules/` trees, and Playwright browsers are not cached.

Each Composer project job also caches two sets of quality files in GitHub Actions cache.

| Cache | Key inputs |
| --- | --- |
| PHPStan result cache, `vendor/phpstan/cache/resultCache.php` | `composer.lock`, `phpstan.neon` |
| Pint and Rector caches, `vendor/pint.cache` and `vendor/rector/cache` | `composer.lock`, `pint.json`, `rector.php` |

A run on main restores quality caches for its branch, then the project. A PR restores quality caches only from main and saves none. CI does not cache TIA graphs in Actions cache: every main run records a fresh full graph. Runs outside PRs save quality caches only after passing. Shard timings are committed source, not a cache. These caches are separate from the [main caches](#main-caches), and CI never calls `bin/tia-cache`.

A passing project job on `main` also stages the [cache files](#ci-artifacts) for the main cache store and the [SHA-bound Git ref](#ci-cache-refs) used by workspaces.

The separate `Orbit CLI Binary` workflow builds the toolbox binaries on pull requests. It is not part of `Required checks`. After a `CI` run on `main` passes, the `Orbit CLI Release` workflow publishes that commit's binaries as a GitHub release. See [CLI binaries](/reference/cli-binaries).

## Local checks

In an Orbit task, run `composer test:affected` in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. TIA gives the implementer fast test feedback. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff, and CI runs the full suites on the handoff commit. Outside a task, run both the affected tests and the full checks below. Each project keeps its own dependencies, test configuration, and test-impact graph.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs the affected tests with test-impact analysis (TIA) in parallel processes |
| `composer check` | Runs `guidance:check`, PHPStan, Rector, and the Pint check. It does not run the test suite. |
| `composer test` | Runs the project suite with TIA in parallel processes |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

In `apps/docs`, `composer check` also runs the documentation lint and the API fixture check. `guidance:check` runs the project's guidance tests with a fresh graph separate from the graph that `test:affected` reads. In every project, `bin/guidance-check` creates private temporary directories for the graph and Blade views before Pest starts, then removes them when the command exits. The gate does not depend on `storage/framework/views` existing or on caches written by another user. The check still fails if Boost cannot render guidance or changes the markers required by the hard-stop transformation. The [contributor guide](/contributor-guide#static-analysis) sets the PHPStan level and the rules for fixing findings.

TIA needs PCOV or Xdebug. The first run, or a run without a usable graph, runs the full suite. Later runs select the tests that the changes affect. An explicit path or a partial-selection option such as `--filter`, `--group`, or `--testsuite` turns TIA off for that run. Confirm that the tests of the feature ran. Zero selected tests is not acceptance evidence. Every `tests/Pest.php` stores its graph in the directory that `ORBIT_TIA_DIRECTORY` names, and in `.orbit-tia` by default. E2E scenario runs use the directory in `ORBIT_SCENARIO_TIA_DIRECTORY` instead, which must be under the primary checkout's `.e2e/scenarios/runs/`.

Each project requires the `nckrtl/pestphp-monorepo` Pest fork from its lock file. The fork adds the monorepo and test-impact fixes. To adopt a new release, update the dependency and the lock file in every project, then run the project checks. A runner change invalidates the published graphs, so the first run after it records a fresh graph.

Root `bin/test` queues the five project suites through the same weighted lane allocator as the candidate gate. The total of concurrent Pest workers stays within the [available process budget](#parallel-processes), including when the budget is smaller than the number of suites. Each finished suite frees its share for the remaining suites.

### Parallel processes

Pest, PHPStan, Rector, and Pint use one process rule in `bin/check-processes`. At check start it reserves 640 MiB for gate overhead, then divides the remaining available memory by the measured allocation of 1664 MiB per worker, takes the smaller of that count and the available CPU count, and allows at least one worker.

On Linux it reads CPU affinity and cgroup v2 CPU quotas. Available memory is the smaller of `/proc/meminfo`'s `MemAvailable` and each bounded cgroup's limit minus its working set, including ancestor bounds. The working set is `memory.current` minus `memory.stat`'s reclaimable `inactive_file` pages. On macOS it reads the CPU count and the free, inactive, and speculative pages from `vm_stat`. Unreadable resources fail the check.

The floor of one can overcommit memory and OOM when less than the reserve plus one worker is available. The rule assumes one gate per task VM and does not reserve resources between concurrent gates on a shared host. CPU is schedulable capacity, not idle capacity: topology processes share it and can slow the gate.

| Tool | How it reads the process count |
| --- | --- |
| Pest | `--processes=$(../../bin/check-processes)` in the `test` and `test:affected` scripts |
| Pint | `--parallel --max-processes=$(../../bin/check-processes)` in the `format:check` script |
| PHPStan | `tools/phpstan/parallel.php`, which every `phpstan.neon` includes |
| Rector | `->withParallel()` in `rector.php`, with the value from `tools/check-processes.php` |

Each parallel Rector worker keeps its cache in its own directory under `vendor/rector/cache/workers/`. `tools/rector-cache.php` names the directory and removes it when the worker exits. The next run's `--clear-cache` removes a directory that a killed worker left. Rector writes a cache file with a plain copy, and every worker rewrites the same configuration entry when it starts. Workers that share a directory can read a half-written file and fail with a syntax error.

The PHP helper `tools/check-processes.php` invokes `bin/check-processes`; it contains no allocation rule. The candidate gate and `bin/test` use `tools/check_budget.py` to split the resource-derived budget between their lanes and suites with the internal `ORBIT_CHECK_PROCESSES` variable. A positive integer lane share is used verbatim, and an invalid share fails the check. Do not set it on a Project or in an operator shell. CI keeps its explicit runner process counts, as [Gateway shards](#gateway-shards) describes.

### The candidate gate

Root `composer check` runs `bin/review-check`, the candidate gate. The Orbit Project uses it as its task check, so it runs in the task VM at every subtask handoff. The gate runs only fast checks and no test suite. The full suite of each handoff runs in CI on the exact handoff commit, as [Tasks: Handoff CI](/reference/tasks#handoff-ci) describes. A merge still needs the full suite on the final head.

The gate checks the working tree as it is, including uncommitted and untracked files. It runs these checks.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. The fallback is the merge base with local `main`. Without a merge base, this check fails.
2. It runs `bin/project-vocabulary`, `bin/env-docs`, `bin/tooling-baseline`, and `bin/docs-nav`.
3. It seeds absent quality caches with `bin/worktree-cache`.
4. It checks the five Composer projects, as the next paragraph describes. [Projects run at the same time](#projects-run-at-the-same-time) explains how steps 4 to 8 share the machine.
5. When the candidate changes `apps/web`, `docs/openapi.json`, or `apps/pi-server`, it adds the matching checks, as [Web and Pi server checks](#web-and-pi-server-checks) describes.
6. It checks generated API artifacts when their inputs change, as [Generated artifacts](#generated-artifacts) describes.
7. When the candidate changes the Rust agent, the desktop app, or `.github/workflows/ci.yml`, it runs `cargo fmt --all -- --check` in the matching Rust project.
8. It runs `bin/check-classification-fakes` on changed test sources. A change to the checker itself checks every project’s tests.

The vocabulary check scans SQL names and expressions in the committed SQLite schema, but leaves recorded trigger error messages unchanged. The stock dump preserves those messages from applied migrations; new migration source still uses the current vocabulary.

When the candidate changes `apps/gateway/resources`, `apps/gateway/tests/Programs`, `apps/gateway/ruff.toml`, or `bin/remote-programs-check`, the gate also runs `bin/remote-programs-check`. See [Remote programs](/reference/remote-programs#lint-and-direct-tests).

For each Composer project, the gate runs `composer validate --strict` and `composer check`. Its `composer check` runs Pint and Rector only on the files that the candidate affects. [Changed-file quality checks](#changed-file-quality-checks) lists them.

Files under `apps/gateway/tests/Rehearsal/` require an owned disposable VM and are only listed by this gate. Run these native rehearsals explicitly on that VM; the ordinary gate neither runs them on its host nor disables their safety checks.

#### Projects run at the same time

The gate groups its project checks by project and runs the groups at the same time. Each group runs its own commands in order. `bin/check-processes` supplies the budget of the whole gate, and the tools of all groups together stay within it. The gate shares the budget in three ways:

1. It runs one more group than half the budget at once. So the largest group can keep at least half of the budget.
2. It gives each command a share by the size of its group's projects, counted in test files. The share compares the group with the groups that still run.
3. It holds back a command until a process is free. Each command frees its processes at its end.

The largest group starts first. Its later commands get the processes that finished groups freed.

A budget of 2 or less runs one group at a time. The budget counts workers, not each tool's main process.

With a budget of 4, the Gateway's checks run with two processes while two other groups run with one each. A budget of 1 checks one project at a time. The gate prints each result when the command ends. The receipt lists the checks in the gate's own order.

The web checks and the repository checks run in the Gateway's group, after the Gateway's checks. `bin/docs-openapi --check` boots the Gateway application. The other groups share no files that a check writes.

#### Changed-file quality checks

The gate's `composer check` sets `ORBIT_CHECK_SCOPE=changed` and `ORBIT_CHECK_BASE` to the merge base with `origin/main`. `bin/quality-scope` reads them and narrows Pint and Rector. PHPStan keeps its result cache and analyses the whole project, so `composer check` runs it before Rector. The changed files are the PHP files that differ from the merge base: committed on the branch, staged, unstaged, or untracked.

| Tool | Files it checks in the gate |
| --- | --- |
| Pint | The changed PHP files that `pint.json` formats. With none, it logs `Pint: no changed PHP files to check` and passes. |
| Rector | The changed files in its paths, the files that PHPStan records as depending on a changed file, and the files in its paths that PHPStan does not analyse |

Rector reads the dependents from PHPStan's result cache, `vendor/phpstan/cache/resultCache.php`. It still runs with `--clear-cache`. The tool runs on all its files, and logs why, in these cases:

- The result cache is missing or cannot be read.
- Another PHPStan than the installed one wrote the result cache, or its format is not one that `bin/quality-scope` reads. The script lists the formats it knows.
- The result cache does not describe the files as they are now: a file it records has another hash, or a file in PHPStan's paths is not in it.
- The candidate deletes a PHP file that PHPStan analyses or records. After PHPStan runs again, nothing records the files that used it.
- The candidate changes the tool's configuration, `composer.json`, or `composer.lock`.
- The gate has no merge base.

CI, a plain `composer check`, and a run with `CI` or `GITHUB_ACTIONS` set check every file. Only the gate sets the scope. To make the gate check every file, run it with `ORBIT_CHECK_SCOPE=full`.

#### Generated artifacts

Changes under `apps/gateway`, `apps/cli`, or `packages/php-sdk`, to the gate or either API generator, to `docs/openapi.json` or `docs/docs.json`, or to the generated MCP outputs run `bin/docs-openapi --check` and `bin/mcp-tools --check`. These checks generate the expected bytes in memory and print a unified diff when files are stale. They leave the candidate files unchanged. A stale-artifact failure names the exact regeneration command: `bin/docs-openapi` or `bin/mcp-tools`. Regenerate OpenAPI first when both are stale, then run `cd apps/web && bun run types` to update the web schema.

Changes to `docs/openapi.json`, `packages/php-sdk/fixtures`, `apps/web/fixtures`, the fixture checker, or the gate also run `bin/api-fixtures --check` explicitly. Docs’ `composer check` continues to validate the SDK and web fixtures on every run.

The gate checks the local candidate tree. CI runs every test suite, including the architecture, browser, privileged, and subprocess tests. CI also runs Rust clippy and tests, release builds, and the checks of the PR merge tree.

#### Web and Pi server checks

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The build also requires nonempty `dist/index.html` and `dist/version.json`, as CI does. The generated-types check writes `openapi-typescript` output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. On failure it prints a unified diff and says to run `cd apps/web && bun run types`. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, and `bun run build` there. Its tests run in CI. Rust formatting runs for changed Rust projects; the remaining Rust checks run only in CI.

A command whose program is missing fails with `<program>: required tool not found`. The generated-types check runs in a shell, so a tool that is missing there fails with exit code 127. The gate never skips a selected check.

#### Finding checks

Three checks reject review findings that code can detect. `composer check` runs the first two in every PHP project through the shared rules in `tools/phpstan/`.

| Check | Rejects |
| --- | --- |
| `phpstan-disallowed-calls` | A call to `strtotime()`. Use Carbon parsing. |
| `orbit.inlineVarOverride` | An inline `@var`, `@phpstan-var`, or `@psalm-var` inside a method body. Fix the type at its source. |
| `bin/check-classification-fakes` | A changed test that calls `Classification::fake()` without chaining `preventStrayClassifications()`. |

Each failure names the file and line.

#### Receipt

The gate writes a receipt, `result.json`, and one log per command in a new `review-*` directory under `orbit-checks/<HEAD>/` in the Git common directory. Each command also prints its duration in seconds, so stored task output shows slow steps without opening the receipt. Cache seeding prints its duration too. The receipt passes only when at least one command ran, every command passed, and the commit and the working tree did not change during the run.

Before it writes, the gate removes the `review-*` directories its own Unix user wrote more than a day ago, and `<HEAD>` directories that stayed empty for a day. It cannot remove another user's reports; each user cleans up its own on its next run.

### Gateway test databases

The Gateway test bootstrap keeps tests away from real databases. Before Laravel loads its configuration, it sets `APP_ENV=testing`, the SQLite driver, and the test database in the process environment and in the PHP environment and server variables. After Laravel loads its configuration, a guard checks the effective connection, including a connection URL and cached configuration.

| Input | Test behavior |
| --- | --- |
| No `ORBIT_TEST_DATABASE` | In-memory SQLite |
| Inherited `DB_DATABASE`, `DB_URL`, or `DB_CONNECTION` | The test value replaces it |
| `ORBIT_TEST_DATABASE` set to an absolute path of an `orbit-gateway-test-*` file in the system temporary directory | That disposable SQLite file |
| `ORBIT_TEST_DATABASE` set to any other path, or to a symbolic link | The test bootstrap stops with a message that names the rule |
| Any other driver, database, or application environment | The run stops before migrations with a database safety refusal |

Allocate a new temporary file for each run that needs a file-backed database. Never point `ORBIT_TEST_DATABASE` at a Gateway runtime database, another application's database, or a backup.

An unexpected refusal usually means that Laravel loaded a stale cached configuration. In `apps/gateway`, run `unset APP_CONFIG_CACHE` and `php artisan config:clear`, then run the same command again. Do not disable the guard.

## Worktrees

`bin/worktree-create ISSUE` creates a linked worktree for a local feature. For `ORB-217`, it uses branch `orb-217` and the directory `/fast/worktrees/orbit/orb-217`. It fetches `origin` first and does not change the primary checkout. Set another absolute base directory with `git config orbit.worktreeRoot PATH`. The base must be outside the primary checkout.

The command reuses what already exists.

| State | Result |
| --- | --- |
| The branch has a clean registered worktree | The command reuses that worktree |
| That worktree is dirty or on another branch | The command refuses |
| Only the local branch exists | The command adds a worktree for it |
| Only `origin/orb-217` exists | The command creates a local branch that tracks it |
| No branch exists | The command creates the branch from `origin/main` |

It refuses a path that exists but is not a registered worktree. Then it runs `bin/bootstrap` in the worktree.

`bin/bootstrap` installs all five Composer projects, seeds the caches, and validates each project's guidance. Then it runs `composer test:affected` and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the guidance validation.

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It queues a background cache refresh and removes the worktree. Then it deletes the merged branch. It releases no topology: a topology runs only in a task VM and goes with it.

## Main caches

Main caches let a new checkout start with warm test-impact graphs and warm Pint and PHPStan result caches. Main CI produces them: each project job that passes on `main` uploads its graph and quality caches, and a background worker imports them. No local command and no deployment runs a test suite, Pint, or PHPStan to fill the caches. Each repository keeps one store in its Git common directory under `orbit-tia/v1`. Linked worktrees share it.

| Path under `orbit-tia/v1` | Content |
| --- | --- |
| `published/<project>.json` | One test-impact graph per Composer project |
| `quality/<project>/<tool>.json` | One Pint or PHPStan result cache per project |
| `requests.json` | Pending refresh requests, the last result per project, and open correctness failures |
| `refresh.log`, `run-*/` | The worker log and the per-run logs |
| `runners/` | The frozen worker scripts |
| `requests.lock`, `refresh.lock` | The queue lock and the worker lock |

Each publication records the tested commit, the CI run, a checksum, and the inputs it is valid for. A new publication must descend from the one it replaces, so older results never replace newer ones.

### CI cache refs

The `publish-ci-cache` job in `ci.yml` publishes workspace test caches after `Required checks` succeeds on `main`. It runs for a main push, scheduled run, or manual dispatch on main, never for a pull request, fork, or other branch. It gathers the five Composer projects' cache exports from the same run and tested SHA. All project jobs, including Gateway privileged checks and the full suites' subprocess tests, must pass before publication. The publisher checks the `required` job's result explicitly and uses `!cancelled()` so skipped PR-only dependencies do not block publication. A failed, cancelled, or incomplete run publishes no ref.

Only this job receives `permissions: contents: write`. It pushes to the repository with its short-lived `GITHUB_TOKEN`; no personal token or Gateway credential is stored in CI. The workflow's other jobs keep their existing permissions. The Gateway later fetches the ref with its GitHub App's repository-scoped `Contents: read` token, through the same protected Git path as source reads. Neither a task VM nor an agent receives either credential.

The job runs `bin/ci-cache-ref publish` to write a parentless cache-only Git commit. Its tree contains no source files or source history, and the job and pushes it to `refs/orbit/ci-cache/<sha>`. The suffix is the full tested code SHA. It is not the cache commit's SHA. The tree groups payloads by repository-relative project path.

Each project has a schema-2 manifest, a Pest graph, and optional Pint and PHPStan caches. The manifest records the tested code SHA, project, CI run and exporting attempt, checksums of all payload files, dependency and test inputs, each tool's configuration, and the PHP minor version. A partial re-run may keep exports from earlier successful attempts of the same run and SHA. [Rector runs without a cache](#ci-caches-stay-separate); this transport does not change that policy.

Before pushing, the publisher verifies that every manifest and graph names the run's tested SHA and that the required project exports are complete. Consumers repeat the SHA and checksum checks; a ref name alone is not evidence that the payload fits. The source of trust is the successful main workflow and repository write access, not the checksums. PHPStan caches contain executable PHP.

A ref is immutable while retained. A retry at the same code SHA keeps an existing valid publication rather than force-pushing it; a conflicting publication fails the publishing job. Publications for different code SHAs use different refs, so an older run finishing later cannot replace a newer commit's cache. Publication failure leaves the code available without a cache ref and records the failure in the publishing job log.

A new task VM workspace starts at the `origin/<default branch>` tip. For a Project that declares `@orbit/ci-caches`, the Gateway independently selects the newest commit on that tip's first-parent history with a published `refs/orbit/ci-cache/<sha>` ref, records it in `refs/worktree/orbit/ci-baseline`, and imports its caches. It logs the baseline SHA and its distance from the tip, including zero for a cached tip.

If no ancestor has a cache ref, the workspace starts cold with no recorded cache baseline. If the optional ref fetch fails, the Gateway discards the mirror's cache refs and logs the fetch failure as the cause of the cold start. Neither case fails the workspace claim. It does not wait or poll for publication, or try another baseline if the selected cache is invalid. A baseline retry moves the untouched workspace to the current default branch tip and selects its cache baseline by the same rule.

Existing task branches, seeded worktrees, and incoming pull requests keep their selected source commit. The ref for the remote default branch still tracks its latest head. The PR must pass the normal full-suite gate against latest main before merging.

The Gateway fetches the `refs/orbit/ci-cache/*` namespace into its mirror alongside source refs. Cache fetching is optional: a cache-ref miss must not fail the required source fetch. The Gateway sends only the ref for the selected code baseline to a workspace over SSH. [Instance setup](/reference/instance-setup#import-ci-main-caches) owns the compatibility checks and import. A missing or incompatible payload leaves checks cold at the selected code commit. A cache ref for another SHA, even a newer one, is never substituted. The workspace imports Git objects, not an Actions download or a base64 command payload.

### Cache ref retention

The publisher prunes only `refs/orbit/ci-cache/*` after a successful publication. It retains publications for 14 days from publication time and always keeps the newest successful tested main commit's ref, even if it is older than 14 days. Main commit ancestry, not job completion order, identifies that newest commit. Pruning never touches source branches, tags, or other refs. Each successful scheduled main run also performs this cleanup; if CI stops, cleanup stops rather than expiring the last useful publication.

The Gateway removes upstream-deleted refs only from its mirror's cache namespace. It does not delete an active workspace's local cache objects or private tool caches. Ref deletion removes a future fetch opportunity, not already imported caches; an old baseline whose ref has been pruned starts cold. Git garbage collection can reclaim unreferenced cache objects later. This retention is separate from Actions artifact retention, package caches, and machine images.

### CI artifacts

After a project job passes on `main`, CI uploads the artifact `sandbox-tia-<index>-<commit>` and keeps it for 14 days. Pull request runs upload none. These artifacts stage the exports for `publish-ci-cache` and remain an input to the existing background main-cache worker. They are not a workspace transport: the Gateway reads Git refs for `@orbit/ci-caches`, without downloading artifacts or putting their contents in a setup command.

| File | Content |
| --- | --- |
| `graph.json` | The Pest graph. It records the tested commit and a result for every test file it links, from a full suite with `--tia --fresh`. |
| `manifest.json` | Schema version 2, project path, tested commit, CI run and exporting attempt, graph checksum, checksums of `composer.lock`, `tests/Pest.php`, `phpunit.xml`, and `phpunit.xml.dist`, PHP minor version, and the checksum and configuration checksum of each quality cache |
| `quality/pint`, `quality/phpstan` | The Pint and PHPStan result caches that `composer check` wrote in the same job, when it wrote them |

### Seed a checkout

Seeding copies a publication into an absent private cache and never replaces an existing one. `bin/tia-cache seed` copies test-impact graphs. `bin/worktree-cache` copies Pint and PHPStan caches. Bootstrap runs both. The candidate gate runs only `bin/worktree-cache`. A missing or incompatible publication leaves that project cold, and seeding does not fail.

| Cache | Compatible when |
| --- | --- |
| Test-impact graph | The project's `composer.lock`, `tests/Pest.php`, and Pest fingerprint match, the checksum matches, and the tested commit is an ancestor of `HEAD` |
| Pint or PHPStan cache | The project's `composer.lock`, the tool's configuration, and the PHP minor version match, the checksum matches, and the tested commit is an ancestor of `HEAD` |

[Importing a CI cache ref](/reference/instance-setup#import-ci-main-caches) also checks the runtime facts that each tool compares.

The Pest fingerprint holds checksums of the dependency and test configuration files and the PHP minor version, so a graph that CI recorded fits a local checkout with the same inputs. When no quality publication fits, `bin/worktree-cache` copies the cache from the primary checkout when that checkout is clean on `main` and has the same lock file and configuration. Feature worktrees are never a source.

When `ORBIT_MAIN_CACHE_STORE` is set, a checkout seeds only from that store, even when it has no publications, and `bin/worktree-cache` skips the primary-checkout fallback. Bootstrap uses this variable only while seeding. When the variable is unset, a checkout seeds from its own store when that store has publications, and otherwise from the store registered for its origin on the same machine.

The registration is a link at `$XDG_STATE_HOME/orbit/main-cache-stores/<key>`, and `$XDG_STATE_HOME` defaults to `~/.local/state`. The key comes from the origin URL, so the HTTPS and SSH URLs of one repository share it. Each publication registers its store unless another live store already holds the registration. `bin/tia-cache register` takes the registration for the current repository.

When `ORBIT_MAIN_CACHE_STORE` is unset and the store has publications that lag the checkout's fetched `main`, seeding queues a background refresh for the lagging projects. It does not queue a project whose last refresh failed at that commit or at a later one.

Orbit task workspaces are linked worktrees of Orbit's `default` repository, starting at the last commit that [deployed](/reference/deployments#development-defaults) there. They share its main cache store but keep private tool caches. `bin/bootstrap` seeds those private caches from the publications, and `bin/review-check` seeds the quality caches. A Project setup step reflinks each dependency tree and private cache from `ORBIT_SEED_PATH` first.

### Refresh from CI

`bin/tia-cache refresh --background` records a request and starts one worker when none runs. It returns at once. `bin/worktree-remove` queues it when it removes a merged worktree, and seeding queues it when the store lags. Cache freshness never holds worktree creation, a merge, or cleanup.

The worker uses `git` and the GitHub CLI with the login of the user who runs it. That `gh` login needs read access to the repository and its Actions. For each batch of requests, the worker:

1. fetches `main` from `origin`;
2. reads the newest 30 finished `CI` runs on `main`: pushes, nightly runs, and manual runs;
3. reads the conclusions of each project's jobs in those runs;
4. finds the newest commit on which the project's jobs passed;
5. downloads, checks, and publishes that commit's graph and quality caches.

The worker skips the download when the store already holds that commit. It ignores pull request runs and runs from forks. The Gateway has two jobs on main: `Gateway` and `Gateway privileged`. E2E and the other Composer projects have one job each. Their full suites include the subprocess tests. A failure in any of a project's jobs counts as a failure of the project.

Before it publishes, the worker checks that:

- the manifest names the project, the run, and the commit of that run;
- the test configuration checksums match the files at that commit;
- the graph checksum matches, and the graph describes that commit, holds only passed results, and uses relative paths;
- each quality cache matches its checksum.

The published graph keeps the fingerprint that CI recorded, and its runner identity comes from the lock file and `tests/Pest.php` at the tested commit. A quality publication records the lock file and tool configuration at the tested commit, and the PHP minor version of the CI job. The worker deletes the downloads after each batch.

The worker runs a frozen copy of `bin/tia-cache` as `origin/main` of the store's repository holds it, never the caller's copy. So a clone of an unmerged change only records requests, and it cannot change how the shared store is maintained. Without the tool on that `main`, no worker starts.

Requests stay in `requests.json` until the worker records their outcome, so an interrupted worker leaves them pending. Repeated requests for the same target combine. The worker runs in a new session, so removing the calling worktree does not stop it. It runs at reduced CPU priority, and each command stops after 10 minutes. After each batch, it deletes the run logs that no recorded result names. It removes variables that start with `ORBIT_`, `APP_`, or `DB_`, and `DATABASE_URL`, `CACHE_STORE`, `SESSION_DRIVER`, and `QUEUE_CONNECTION`, from the commands it runs. It keeps `TMPDIR`, `TMP`, and `TEMP`.

For Orbit, the store lives under the stable `default` repository's `.git/orbit-tia/v1` on `/fast`. Register it with `bin/tia-cache register --repository=/fast/apps/orbit/default`. The managed user that owns the store runs the worker, so its `gh` login must read `nckrtl/orbit`.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches, and queues a refresh when the store lags |
| `bin/tia-cache refresh --background` | Queues a refresh and starts a worker when none runs |
| `bin/tia-cache refresh` | Queues a refresh, runs main's copy as the worker in the foreground, and exits nonzero when a requested project has a failure |
| `bin/tia-cache status` | Prints the publications and the maintenance state |
| `bin/tia-cache status --json --remote` | Reads `main` from the remote and prints the maintenance state as JSON, without changing anything |
| `bin/tia-cache register` | Registers this repository's store for its origin |
| `bin/tia-cache import-ci` | Validates and imports one project's CI cache files into private caches without publishing them; workspace files come from the selected Git ref. |

`import-ci` replaces an existing private graph only when CI tested a descendant of that graph's main commit. It exits 3, with the reason, when the payload does not fit the checkout: another test configuration, or a commit outside its history. The project's tests then run cold. Workspace imports also require the exact [selected-ref SHA match](/reference/instance-setup#import-ci-main-caches); an ancestor check alone cannot authorize a different ref. Every cache command accepts `--repository=PATH`. `seed` and `refresh` accept repeatable `--project=apps/docs` options, and they cover all five projects by default.

### Failures on main

`bin/tia-cache status --json --remote` reads the remote `main` and prints the maintenance state. `current` names the projects whose graph publication is at that `main`. The output also has the worker lock state, the pending requests, each project's last result with log paths, the failed projects in `failures`, the open correctness failures, whether a refresh is `needed`, and `refresh_log`. A successful status command reports state. It does not mean that main passes.

The worker sorts failures into two kinds.

| Kind | Cause | Effect |
| --- | --- | --- |
| Check failure, tool `ci` | A job of the project failed or timed out in a finished `CI` run on `main` | A correctness signal on main. It stays open until a run that finished later passes the project's jobs on the same commit or on a descendant. |
| Maintenance failure | `git` or `gh` failed, the newest 30 runs have no finished job for the project, or the artifact is missing or refused | Checkouts keep the previous publications. It never sets or clears a check failure. |

A check failure records the commit, the run ID and URL, and the failed jobs. While it is open, the newest commit on which the project passed stays published. The failure stays open until a later passing run tests the same commit or a descendant. Diagnose a check failure on the failed commit. Fix it, or revert the change that caused it, through a reviewed pull request. The next passing CI run on `main` clears it at the next refresh.

Earlier versions of `bin/tia-cache` ran the checks locally and recorded failures with tool `tia`, `pint`, or `phpstan`. The first refresh that reads the project's CI history replaces them.

## Why it works this way

### The maintainer approves every merge

The maintainer decides whether a feature belongs in Orbit. Turning on [review and merge](/reference/tasks#review-and-merge) for a Project is that decision for every task and listed pull request of the Project.

For named Orbit task work, the maintainer can delegate final review and merge. That delegation is the consent to submit the formal GitHub approval and to merge the reviewed commit. The review records the reviewed head, the evidence, and the verdict in GitHub's review state. A plain comment is not that approval, because its prose does not distinguish approval from requested changes. A Tasks engine subtask approval, or any other agent review, does not replace the final review of the whole pull request and does not authorize unrelated work.

The merge uses the maintainer's GitHub CLI profile. That admin profile bypasses GitHub enforcement of `Required checks`, and GitHub does not require an approving review, so the DevOps reviewer checks the formal approval and green CI before merging the reviewed commit. A ruleset change that requires an approving review is deferred: it needs new credentials, API behavior, and deployment work. The App merge of [review and merge](/reference/tasks#merge-on-green) has no admin bypass, so the ruleset binds it. It runs only for Projects that opt in.

### Every project passes the gate at each handoff

The gate checks all five Composer projects whatever the candidate changes. Checking the changed project alone is a rejected alternative, because the other projects would then have no quality check before review. The gate runs at each handoff, before review. Running it only in the independent review is also rejected, because each deterministic failure would then cost a reviewer turn and a correction round before it reached the implementer.

### Web and Pi server checks join the gate, Rust stays in CI

A change to the web app or the Pi server could pass the gate and then fail a required CI job. So the gate runs the same fast checks as those CI jobs when their paths change. Their tests run in CI. Running every CI job for every subtask is a rejected alternative, because path selection keeps unrelated work fast. Rust cross-compilation is too slow for each handoff, so the Rust agent stays a CI check.

### A missing tool fails the gate

A green gate must mean that every selected check ran. Skipping a check when its tool is absent would pass a workspace that happens to lack `bun` or `git`.

### Repeated findings become checks

Reviewers report `strtotime()`, inline type overrides, and unguarded classification fakes again and again. Each one is deterministic, so a check rejects it before review. Asking reviewers to remember them is a rejected alternative, because it spends a review round on a finding that code can detect.

### Only the newest waiting main commit runs

Pushes to `main` share one concurrency group. A running run always finishes, and a newer push replaces the waiting one. An automatic Gateway release ships only a commit with its own successful `Required checks` run, so a replaced commit never ships by itself. It ships inside the next green commit, which descends from it and whose full suites cover its changes.

A group per `main` commit is a rejected alternative. It gave every commit a result, but during a burst of merges every commit queued a full run on the runners on Sabre, and the newest commit, the one a release ships, ran last. On 2026-10-08 about a dozen merges in 15 minutes delayed the release by 30 to 40 minutes.

The cost is a smaller fallback when the newest run fails. The release resolver examines at most 20 commits, and a replaced commit has no result to fall back to. After a burst of more than 20 merges whose newest run fails, auto-release waits for a fix to merge and pass. A manual re-run of an older `main` run also joins the shared group, so it replaces a waiting run.

### Main caches come only from clean main

A feature graph can hold unmerged code, failed tests, or working edits, so it cannot prove the state of main. Only a job that passed on main publishes, and each checkout writes to its own private copy. One writable graph shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results. Recording every new worktree from scratch is also rejected, because unchanged projects would repeat the full run.

### Only main CI publishes

CI already tests every `main` commit, so its graphs and quality caches cost nothing extra. A deployment never runs a test suite.

Running the suites on a development machine to fill the store is a rejected alternative. It repeated the work of CI, competed with other agents for the processors, and inside a deployment it exceeded the deployment's deadline. Its results also depended on the host: a test that passes in CI failed on a host with other file ACLs, and that failure held merges as a correctness failure on main. Publishing the caches of a clean bootstrap on `main` is rejected for the same reason, and because two producers would race for one store.

Pest's own baselined mode fetches one artifact per repository with `gh`. Orbit has one artifact per Composer project and a store that many worktrees share, so the worker imports the artifacts into that store instead. A separate cache service is also rejected, because GitHub already keeps the artifacts and linked worktrees already share one store.

### Maintenance runs in the background

Waiting for every refresh before a new worktree or a merge would serialize delivery behind maintenance. A checkout starts from the newest compatible publication while the worker catches up. The cost is that a failure on main can surface after another feature merges. One worker per repository covers all five projects, so they share one list of runs and one download per run. A failed download or API call is not a source regression, so only a failed CI job counts as a correctness signal.

A failure stays open until a run that finished later passes on that commit or a descendant. Clearing it after an unrelated passing run is rejected: that run may not contain the failing commit.

### Clones find the store through a registration

The Gateway provisions a task workspace as an independent clone, and it runs checks for every Project without knowing one repository's cache layout. So the repository's own scripts find the store through a link in the user's state directory. Setting `ORBIT_MAIN_CACHE_STORE` in the Gateway or in Project setup steps is a rejected alternative, because a fixed path breaks when the primary checkout moves. A global Git setting is also rejected, because it needs a manual step on every machine and goes stale without notice.

### Main runs record full suites

Main always runs full suites with `--tia --fresh`. This keeps merge and release evidence independent of TIA's coverage gaps while still recording the graph that speeds up task workspaces. A main affected-test selector and separate subprocess-only lanes are rejected: they add selection rules without proving every test passed. Reusing a PR result is also rejected because main can differ from the tested PR commit.

### Full suites gate merges; TIA serves the task loop

Nick decided on 2026-10-19 that PR `Required checks` run the full test suite of every touched project on the merge ref, with TIA disabled. Orbit runs all five Composer suites on each PR, including architecture and subprocess tests.

Review and merge with `merge_check: Required checks` waits for that check on the reviewed head. Only the pull request's own `pull_request` run for that head counts, also when the head is the last handoff commit, so the merge with the current base is tested before it lands. GitHub’s merge API still checks the expected head. Main pushes, schedules and dispatches also run full suites while recording fresh TIA graphs.

The [declaration-data audit](/solutions/test-impact-declaration-data) found that enum cases, constants, property and parameter defaults, and attribute arguments can change behavior without per-test coverage edges for later readers. Its seven-case fixture produced a TIA false green while the full suite failed four cases. A general declaration selector would widen 496 of 580 entries on main’s first-parent history that changed app code (85.5%, about 86%). That selector is rejected.

Nick decided on 2026-10-20 that each task handoff runs the full suites in sharded CI on the pushed handoff commit, as [Tasks: Handoff CI](/reference/tasks#handoff-ci) describes. The task VM keeps only the fast checks. So no handoff, rework, or fixup depends on a TIA selection. A false green from declaration data cannot pass a handoff. Running the full suites in the task VM is a rejected alternative: there the Gateway suite alone took 2614 seconds on one worker, and CI shards it. TIA stays the implementer's fast feedback through `composer test:affected`.

Gateway uses stock Pest time-balanced shards to keep the full PR run short. Committed timings keep all four jobs on one version without a planner or cache race. Quality checks overlap the shards. Sharding on main remains rejected because Pest writes one whole graph per run.

### Gateway test time limits

Gateway uses stock PHPUnit per-test limits: `enforceTimeLimit="true"`, `defaultTimeLimit="42"`, `failOnRisky="true"`, and `stopOnRisky="true"` in `apps/gateway/phpunit.xml`. All cases remain unsized. The installed Pest cannot declare PHPUnit sizes; do not substitute size-named groups or add a custom sizing mechanism.

The 42-second default is the smallest whole number at least three times the slowest unsized case in a full parallel run on the four-CPU task VM under CPU contention: 13.979728 seconds. Remeasure the full parallel suite on a loaded host before changing the default, and keep that minimum three-times margin. Fix unexpectedly slow or risky cases at their cause rather than weakening the tests or bypassing the limit.

Timeouts fail the run. `stopOnRisky` prevents later cases in the same worker from consuming state left by interrupted cleanup; it does not guarantee cleanup completes. The Gateway PHP recipe requires `pcntl`, and the shared CI PHP action verifies the recipe before running suites. Keep that requirement in the recipe, not individual jobs.

Give datasets containing invalid UTF-8 readable names. This keeps JUnit test names valid without changing the input bytes that the test validates.

Process fixtures acknowledge readiness from their final executable before a case acts on them; a PID alone does not prove startup finished. Tests of leftover cleanup after `SIGKILL` first observe that the check is lost, then cancel it. Sending a signal does not mean the process has exited.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner, so it cannot read the repository store. On `main` it uploads the artifacts that the store imports. Pint and PHPStan key their caches on paths relative to the project, so their CI caches work in any checkout.

CI does not restore or save Rector's cache. Every project's `composer rector` uses `--clear-cache`, including runs through `composer check` in CI and the local review gate. Rector's unchanged-file cache does not track all class dependencies: a parent-only property change can leave an unchanged child's missing `#[Override]` undetected. Clearing the cache trades warm-run speed for a complete check of the configured Rector paths; Pint, PHPStan, and Pest keep their existing caches. The candidate gate narrows the files instead of keeping a cache, as [The gate checks changed files](#the-gate-checks-changed-files) explains.

The regression in `apps/docs/tests/Unit/ToolingBaselineTest.php` warms the cache with an empty parent and a child that declares `public string $name = '';`, then adds that same property only to the parent. Before the fix, the second check exits 0 without reporting the child. With the project's check command, it exits 2 and proposes `#[\Override]` on the child's property. The dry run leaves the child unchanged.

Gateway timings were measured in the task workspace on Linux x86_64, with four CPUs, PHP 8.5.11, and Rector 2.6.7. They were 126.24 seconds for an uncached run and 0.65 seconds for a warm run before the fix. After adding `--clear-cache`, `composer rector` took 154.77 seconds on the first run and 118.92 seconds on the repeat. These are wall-clock samples, not a performance guarantee: checks now pay the full analysis cost on every run.

### The gate checks changed files

A new Orbit Instance or task VM starts at a `main` commit that CI already checked. Before this change, `composer check` there took about 10 minutes, almost all of it in the quality tools. Rector ran in one process without a cache, and Pint ran in one process. In the Gateway, Rector took about 155 seconds and a cold Pint about 288 seconds.

The gate now checks with Pint only the files the candidate changes, because Pint judges each file on its own. Rector's findings in a file can depend on other files, such as a parent class. So Rector also checks every file that PHPStan records as depending on a changed file. PHPStan records these dependents for its own result cache, and the gate runs PHPStan first, so the record describes the files as they are. A record that does not match the files makes Rector check everything. The gate never checks less than it can prove covers the change.

CI checks every file. It tests the merge result of a pull request, and its result decides the merge. The gate gives fast feedback at each handoff.

All four tools run in parallel processes. The old limits of two PHPStan and Pest processes had no recorded reason. Rector ran in one process, and parallel workers that share its cache directory can fail on a half-written cache file, so each worker now has its own directory. With 16 processes on a 16-core machine, a full Rector run in the Gateway takes about 32 seconds with the same findings, and a cold Pint run about 37 seconds.

Memory and CPU quotas bound the process count. The gate shares the resource-derived budget between the projects it runs at once. The Gateway is the largest project, so it gets the largest share. Each lane passes its share to every tool through `ORBIT_CHECK_PROCESSES`.

A complete normal-VM gate on Beast on 2026-10-19 UTC exercised the Gateway workers. The gate still ran `test:affected` then. Pi 0.87.1 stayed resident in idle RPC mode, with no model calls. The real diff against `353ef196c` selected all 847 Gateway test files from 104 changed files. Gateway `test:affected` passed 13,954 tests with 21 skips and 108,537 assertions in 2614.290 seconds. Its heaviest worker reached 1506.1 MiB `VmHWM`. The final allocation is 1664 MiB, about 10.5% above that measured peak.

| VM and selection | Workers | Gate peak summed RSS | Gate kernel memory peak | Minimum VM available memory | Gate controller peak PSS | Gate seconds |
| --- | --- | --- | --- | --- | --- | --- |
| Normal: 2 CPUs, 4 GiB, resident Pi, Gateway and E2E tests | 1 | 3.864 GiB | 2.094 GiB | 1.185 GiB | 538.0 MiB | 3160.337 |

That root `composer check` passed with zero OOM events. The nominal 200 ms sampler's longest interval was 1.214 seconds. Forked test children share pages, so summed RSS counts those mappings repeatedly; the continuous kernel peak records the gate's charged memory. The measured gate used one worker. The final rule also selects one worker from its recorded 3.054 GiB available at start, so its lane shares are unchanged.

The final 640 MiB reserve gives about 19% headroom above the measured 538.0 MiB controller PSS. These counts use the final rule at the recorded starting memory:

| VM | Topology | Available memory | Workers |
| --- | --- | --- | --- |
| Normal: 2 CPUs, 4 GiB, resident Pi | None supported | 3.054 GiB | 1 |
| Medium: 4 CPUs, 8 GiB, resident Pi | None | 7.056 GiB | 3 |
| Medium: 4 CPUs, 8 GiB | Gateway, operator, app-dev | 2.835 GiB | 1 |
| Large: 6 CPUs, 12 GiB | Gateway, operator, app-dev, app-prod | 5.722 GiB | 3 |

Size alone does not determine the count. The topology rows replay the final allocation over captured resources; they do not measure another complete gate under that allocation. Four workers need at least 7.125 GiB available.

### Findings are fixed, not silenced

A PHPStan ignore, a baseline, or a silencing cast keeps the wrong type in the code, and new code can repeat it. Fixing the value at its source lets CI catch these errors before review, so a reviewer spends the review on behavior. The [contributor guide](/contributor-guide#static-analysis) lists the rules.

### Shared-worker feedback

The task check runs no Gateway tests, so it needs neither host sudo nor another Unix identity. The Gateway `privileged` test group needs both and runs in the required Gateway privileged CI job. Check report directories permit group reads so the worker can inspect failures.
