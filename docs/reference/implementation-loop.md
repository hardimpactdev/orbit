---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/{AdrLifecycle,DocsImpact}.php
  - bin/{review-check,project-vocabulary,bug-repro,task-group-check,pr-head-check,deploy-verify,test,ci-cache-ref,check-pest-shards,worktree-cache,worktree-create,worktree-remove,check-classification-fakes,check-processes,quality-scope,php-build}
  - tools/{check-processes,rector-cache}.php
  - tools/check_budget.py
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/setup.sh}
  - .github/{workflows/*.yml,actions/{php,no-sudo}/action.yml}
  - apps/gateway/{phpunit.xml,database/migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php,tests/Support/TestDatabaseEnvironment.php,tests/Support/TestDatabaseGuard.php,tests/Support/ForkedPythonPrograms.php,tests/Support/FixtureProcessRunner.php,tests/Support/GatewayReleaseFixture.php,tests/Support/TaskSchedulerTickFixtures.php,tests/Feature/Domain/TaskWorkspaceTmpdirTest.php,tests/Feature/Domain/ScheduleValidationTest.php,tests/Feature/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycleTest.php}
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

CI selects Node.js from the root `.node-version`, currently `24.22.1`, which includes the [October 21, 2026 security fixes](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases). The machine recipe pins the same official Node artifacts for both architectures. `tools/ci-runners/setup.sh container NAME` installs the recipe's Node, Vite+, dispatcher, and pnpm with the shared Node/image installer. It removes Ubuntu's separate `nodejs` package only when the simulated removal affects that package alone. Run this command only after stopping an idle runner service. GitHub Actions' bundled Node executables belong to the runner distribution; changing the project Node pin does not patch them. Verify those executables separately when upgrading the runner.

CI passes its job-scoped, read-only `GITHUB_TOKEN` to Composer through workflow-level `COMPOSER_AUTH`. GitHub API requests use `github-oauth`; `codeload.github.com` uses bearer authentication too, because Composer does not send the API OAuth header to that host. The token stays in the workflow environment and is not written to an auth file.

GitHub CI runs on every pull request, on every push to `main`, every night on `main`, and on manual dispatch. Change runs select touched projects and their consumers. Main, nightly, and manual runs select everything. It has these jobs.

| Job | Checks |
| --- | --- |
| Changed projects | Tests the path map, compares the change head with its merge base, and selects projects and consumers before suite jobs start |
| One job per selected Composer project: CLI, Docs, Gateway, E2E, PHP SDK | `composer validate --strict`, `composer check`, the classification-fakes check, and the full tests. When selected, Gateway runs quality checks and the shard-union check here; its tests run in the shard jobs. Docs also runs `bin/env-docs` |
| Gateway shards (five) | When Gateway is touched: stock Pest shards of the full non-privileged Gateway suite, independent of the quality job |
| Gateway privileged | When Gateway is touched: the whole privileged group on one disposable GitHub-hosted runner |
| API reference | `bin/project-vocabulary`, `bin/tooling-baseline`, `bin/docs-nav`, `bin/docs-openapi --check`, and `bin/mcp-tools --check` |
| Web | Generated API types, formatting, lint, types, separate `bun run test:unit` and `bun run test:browser` steps, and build. A run on `main` also publishes the build |
| Pi server | Formatting, lint, types, tests, and build |
| Rust agent | `cargo fmt`, `cargo clippy`, tests, and static builds for x86_64 and aarch64, with Cargo caches. Untouched jobs are skipped before they take a runner |
| Remote programs | Installs shellcheck, ruff 0.17.0, and the pinned pytest requirements, then runs `bin/remote-programs-check`: the lint and direct tests of the Gateway's [remote programs](/reference/remote-programs#lint-and-direct-tests) |
| Desktop app | `bun install --frozen-lockfile`, then `cargo fmt` and `cargo clippy` on the Tauri shell with the WebKitGTK libraries installed. Untouched jobs are skipped before they take a runner |
| Required checks | Requires successful detection and successful selected jobs; accepts only explicitly untouched skips, as described below |

Gateway project and shard jobs supply Bun 1.4.2 alongside Node/npm. The package-cache integration tests run real npm and Bun installs against seeded private caches and a local test registry.

The Docs project job also supplies Bun 1.4.2 and installs the Gateway's locked runtime Composer dependencies with `--no-dev` and the web app's locked Bun dependencies. Gateway development tools are not needed for route discovery. Docs keeps its own development dependencies. Its [proof for merging generated files](/contributor-guide#merging-generated-files) runs the real generators in disposable clones, including in each full suite.

### Action pins and token permissions

External actions use full commit SHAs, with the reviewed version in a trailing comment. Dependabot's `github-actions` entry updates these pins. Local actions and reusable workflows keep their repository paths.

Workflows default to `contents: read`. Read-only jobs set `persist-credentials: false` on every checkout. Write permissions belong only to publishing jobs that never run pull-request code: the main CI cache publisher, the CLI release publisher, the recipe artifact mirror, the Git update issue publisher, and the agent release publisher.

Git build watch checks and uploads its report with a read-only token. A separate job downloads that run's report and alone has `issues: write`; it runs only on main's scheduled or manual executions, never on pull requests. Agent compilation and tag validation also use a read-only token. The agent publish job alone has `contents: write`, downloads the build job's assets, and creates the release without checking out or executing the compiled code.

GitHub's repository SHA-pinning policy and Dependabot security updates must be enabled separately by an operator. Workflow changes do not change repository settings.

The Web job runs directly on the GitHub-hosted Ubuntu runner, without a job container. After Bun setup and dependency installation, `bunx playwright install --with-deps --only-shell chromium webkit` installs Chromium's headless shell, WebKit, and their system libraries before the tests. All test launches are headless, so CI does not download the unused full Chromium browser. `bun run test:unit` includes Node tests and real daemon verification in Chromium and WebKit. `bun run test:browser` tests the app in Chromium. Each step reports its own time.

The install step pins the runner’s Ubuntu mirror list to Canonical’s HTTPS archive, as the Desktop job does. Apt retries failed downloads three times and sets 30-second connection and data inactivity timeouts for HTTP and HTTPS. Playwright uses its built-in five download attempts across its download URLs, with a 30-second download connection timeout. The step stops after 10 minutes.

### One PHP build in CI

Every job that runs PHP uses the recipe's [PHP build](/reference/compute-drivers#php-build) of version `php.ci`, through the local action `.github/actions/php`. That action runs [`bin/php-build`](https://github.com/hardimpactdev/orbit/blob/main/bin/php-build), which reads the recipe. So CI and the workspaces that import its [cache refs](#ci-cache-refs) run the same PHP version and the same loaded extensions.

The managed PHP build supports amd64 only. CI installs and verifies the same amd64 pins as Nodes and task VM images. `bin/php-build` refuses PHP on arm64 with `PHP is not available for arm64`; the bump and artifact mirror use only the PHP architectures declared in the recipe.

On a GitHub-hosted runner the action runs these steps:

1. It restores `/var/cache/orbit/php-packages` from `actions/cache`, keyed on `bin/php-build key`, the digest of the PHP pins of the runner's architecture.
2. `sudo bin/php-build install --profile=ci-runner --ci --purge-extras` runs [`php.install-build`](/reference/compute-drivers#install-the-php-build), the installer that Nodes and images run too.
3. A hosted runner image ships other PHP packages and the `ondrej/php` source. `--purge-extras` removes them, and only CI machines use it.
4. `--ci` sets `zend.exception_ignore_args=0` for the CLI. The action installs Composer 2.10.3 from a pinned `composer.phar` at `/usr/local/bin/composer`.
5. A job with input `coverage: none` keeps PCOV loaded and sets `pcov.enabled=0`. The job collects no coverage and still loads the recipe's extensions.
6. `bin/php-build verify --profile=ci-runner` fails unless `PHP_VERSION`, each package version, and `php -m` match the recipe.

A self-hosted runner never installs packages in a job. The action only runs `bin/php-build verify --profile=ci-runner`. It fails with the expected and the found build, and names the step that fixes the host. After a PHP bump, update each runner while it is idle:

- The `ci-runner` role prepares Sabre, Shark, and Beast as workload Nodes. Add it through [normal role assignment](/reference/node-provisioning#ci-runner-role).
- On each host, run `tools/ci-runners/setup.sh container NAME` for its active runner containers. The role converges host software. The script updates each idle container.

#### Merge a PHP build change

A pull request that changes the PHP build runs its CI on the self-hosted runners too, and they only verify. So the runners must hold the new build before that CI runs. For a nightly artifact PR, a red runner check holds the PR until an operator runs these updates from its branch:

On Sabre, Beast, and Shark, run `tools/ci-runners/setup.sh container NAME` for each idle runner container.

Then the pull request's CI runs, and `main` stays green after the merge. After the merge, converge each host through its roles and update each idle runner container.

[Nightly recipe maintenance](/reference/compute-drivers#bump-the-php-build) opens a pull request when Sury publishes a newer build, and converges CI-role hosts in the overnight service tier after merge. `setup-php` is not used. It cannot pin a patch release, and a hosted runner gets a new patch on its own schedule. It also loads about 26 extensions that Orbit does not use, such as `amqp`, `apcu`, `mongodb`, and `soap`.

`Required checks` applies the [project selection and skip rules](#full-suites-gate-merges) below. When Gateway is selected, every shard and the privileged job must pass.

The [Feature delivery merge gate](#full-suites-gate-merges) defines the required PR test coverage. Stock Pest sharding divides Gateway tests among the configured shards, excluding the privileged group. A union check verifies that no test class is missing. The required privileged job runs every privileged test.

Full suites include architecture tests and tests that start PHP subprocesses. CI has no separate lanes for them and no affected-test selector.

The WireGuard key and Instance environment concurrency proofs fork the booted test application, then open fresh connections to private, committed SQLite databases. Socket acknowledgements establish readiness and real lock contention before the holder can finish. The environment capacity race starts with 1,023 stored keys and makes two competing one-key writes, so it tests the 1,024-key boundary without doing bulk setup inside the race. Remote projection and environment I/O stay faked; SQLite transactions, file locks, HTTP middleware, and interrupted Activity finalization stay real.

The tests for PostgreSQL statements and pool integrity share a fixture that reduces process startup. Each case copies a never-started cluster seed and starts its own foreground PostgreSQL 18 server. The server's readiness message replaces `pg_ctl` polling. One `psql` client accepts commands through Symfony's input stream and acknowledges each result; reconnecting to `postgres` after each command releases the prior session and its role.

An explicit `\quit` still ends the real client, including transaction rollback, and the next command starts a fresh client. Clusters and clients remain private to each case. Only the process that created the seed removes it, so a forked contender cannot delete its parent’s fixture.

CLI and Docs limit each local test to two seconds through stock PHPUnit configuration: `enforceTimeLimit`, `defaultTimeLimit`, and the small, medium, and large test timeouts. Every size has the same limit, with no exceptions. `failOnRisky` makes an over-limit test fail the suite. PHPUnit warnings also fail the suite, including the warning when the required `pcntl` extension is missing. Configuration tests pin these settings in both projects. These are per-test limits, not limits on a suite or CI step.

The generated-merge end-to-end proof runs outside Pest through Docs' `composer merge:check`, included in `composer check` and CI's project quality step. The check runs its Python cases sequentially with the real external tools, not under PHPUnit's per-test limit. No proof work runs in Pest setup or hooks.

The check prepares independent branch commits and expected combined trees from current source files and recorded outputs, then gives each case a fresh private repository. Cold generators still prove both merge orders, generated-file conflicts, cache assertions, and independent freshness. Merge callbacks and freshness checks both use the original installed, locked web CLI through Bun, without a bundled or minified replacement.

The merge driver and the runner for this check start Python without loading site packages. The driver imports modules only for the requested mode. This reduces repeated cold startup. The fixture uses Composer's runtime-only autoloaders, so generator subprocesses do not load Pest, PHPUnit or other development tools. Its Composer files remain private; installed packages stay read-only.

Both orders must equal the same recorded generator output and complete source tree. Separate read-only cases rebuild the base and combined fixtures to check every recorded byte. The fixture contains only the source corpus these proofs need, not unrelated applications or tests; see the [fixture guidance](/contributor-guide#3-implement-and-verify).

The animation composition proof runs the real renderer at a 50 ms interval, retaining at least seven outer ticks, three inner phases, off-tick admission and resume, and proportionally scaled cadence bounds. A separate fast test pins the production interval to 300 ms. The proof for printing lines still observes five frames at the real 300 ms cadence in each frame order.

The repository vocabulary proof runs the real executable over the whole maintained corpus. The scanner first selects patterns that occur anywhere in a file, then keeps the same line findings and line-specific exceptions. It does not run every pattern against every clean line.

The Rector cache regression directly checks that the project's `composer rector` command includes the `--clear-cache` argument. The tooling baseline keeps this command identical in all five Composer projects and rejects a command without that argument. This checks the cache invalidation contract inside the test's two-second limit, without starting Rector or moving part of the proof into an unmeasured hook.

CLI applications, configuration, containers, and command objects are freshly built for each feature test. CI adds no private bytecode cache or separate cache-coverage lane.

Every main push, scheduled run and manual dispatch selects all projects: each Composer project's full suite with `pest --parallel`, and the Gateway suite in the Gateway shards. CI records no graph and saves no files for [task workspaces](#ci-cache-refs).

A push to a `task-*-handoff` branch is a task's [handoff commit](/reference/tasks#handoff-ci). CI runs it as it runs a pull request, with the configured Gateway shards and no cache saves.

A new push to a pull request, or to a handoff branch, cancels the older run of that pull request or branch. Pushes to `main` share one concurrency group and never cancel a running run. GitHub keeps one running and one waiting run per group, and a newer push replaces the waiting one. So when several merges land close together, the newest `main` commit is tested next. The commits in between get a cancelled run instead of a result.

The newest main run tests every project in full, including every change in between. [Automatic Gateway releases](/reference/gateway-recovery#automatic-releases) deploy the newest `main` commit with a successful `Required checks` result and skip commits without one, so the release includes the skipped commits. Scheduled and manual full runs have a group per branch and commit, so a push never cancels one.

Every checkout in the run uses `${{ github.sha }}`. On a PR this is the event's merge commit, not a moving `refs/pull/N/merge` lookup. Main and reruns also test that immutable event commit. No job checks out a branch and resets it later.

On `main`, the Web job uploads `apps/web/dist` as the workflow artifact `web-dist-<commit>`, named with the full 40-character commit SHA, and keeps it for 14 days. A manual dispatch and the nightly run on `main` upload it too, so every successful `Required checks` run on `main` comes with the web build of its commit. Automatic releases install only the build of a push or a manual run. The artifact holds the contents of `dist` at its root, so `index.html` and `version.json` are at the top level. The Web job fails when the build lacks either file; open pages read `version.json` to find a newer release ([Updates to open pages](/reference/web-app#updates-to-open-pages)).

The nightly run tests the newest `main` commit again, with every project's full suite. It adds a `Required checks` run to that commit. An automatic release needs every such run to pass, so a failed nightly run keeps that commit from shipping. Every later push also runs full suites, so it ships only when the failure is fixed.

The upload includes hidden files and fails when the build produced nothing. The build runs from a clean checkout with no `VITE_*` variables, as [`bin/web-deploy`](/reference/web-app#release-a-build) builds a release. Pull requests and runs on other branches publish no web build. Successful project jobs on `main` also publish their [quality caches](#ci-artifacts).

On `main`, GitHub enforces three rules. The branch cannot be deleted, and it accepts no force pushes, with no bypass. A change to `main` also needs a passing `Required checks` status from GitHub Actions. The branch does not have to be up to date first, so the merge rules in [Merge and cleanup](#merge-and-cleanup) still check the merged result. GitHub requires no review.

Repository admins bypass the status rule automatically, so the maintainer can push straight to `main`. The bypass also applies to `gh pr merge` from an admin account, with or without `--admin`. An admin who merges must first wait until `Required checks` passes on the pull request's head commit. The [Feature delivery merge gate](#full-suites-gate-merges) defines the PR test boundary.

Each Composer project job checks out full history and follows the [Feature delivery merge gate](#full-suites-gate-merges).

The Docs job's `composer check` also runs `composer docs-lint`. The E2E job runs `bin/bootstrap --dependencies-only` to install every Composer project, because its integration tests use the other projects. It does not install a merge driver, create a Gateway key, seed development caches, or run other projects' guidance checks. Each project's own job checks its guidance. The Gateway job installs the Linux tools that the Gateway tests need and creates the `caddy` user. That step stops after 10 minutes, and apt retries a mirror that does not answer within 30 seconds.

The Desktop app job installs the Tauri development libraries without recommended packages. It pins the runner's Ubuntu mirror list to `https://archive.ubuntu.com/ubuntu/`: the image's preferred Azure mirror served package indexes quickly but stalled on package downloads. The step logs separate update and install timings and keeps its 10-minute limit.

The Gateway job also runs `tests/Feature/Logs/RedactionCasesTest.php` with stock parallel workers and PCRE JIT disabled in both the parent and workers (`--passthru-php="-d pcre.jit=0"`), independently of shard selection. The test checks that PHP received the step's `PCRE_JIT` setting. Once the no-sudo step passes, this step runs even if an earlier test step fails and keeps the large-value redaction regressions in `Required checks`.

Docs quality checks, including Docs lint and the ADR lifecycle rules, and the full Docs test suite run once in the project matrix. Like the other project lanes, Docs checks out the immutable event commit, `github.sha`: the merge commit on pull requests. There is no second Docs merge-ref job.

Hosted jobs run on `ubuntu-26.04`, the Ubuntu release that Nodes run, and use the same uutils coreutils as a Node. Every parallel CI test run omits `--processes` so stock ParaTest detects all available CPUs: 6 in each self-hosted runner container and 4 on GitHub-hosted runners. ParaTest uses Fidry CPU Core Counter, which counts the process's allowed CPUs intersected with the online CPUs on Linux. A read-only call to the installed detector in `ci-beast-1` on 2026-10-21 returned 6, matching `nproc`, rather than the host's 16 threads.

### Gateway shards

Every CI run runs five stock Pest shards, fewer than the [shared runners](#self-hosted-shard-runners), so an Orbit run leaves runners free for other repositories. The `gateway-shards` matrix list in `.github/workflows/ci.yml` is the only source of the count. Change capacity by editing that list. `bin/check-pest-shards` reads its length and requires consecutive shard numbers from 1 through that count. Each job runs `../../bin/pest-plain vendor/bin/pest --parallel --exclude-group=privileged --shard=N/COUNT`; the workflow takes `COUNT` from `strategy.job-total`, so the denominator follows the matrix. The required Gateway privileged job runs every privileged case. The Gateway quality job runs independently, so quality checks overlap all shards. No planner, plan artifact, API polling or hand-built class filter is involved.

#### Gateway tests and privilege

A Gateway test that needs root or another Unix identity belongs to `privileged`. The configured suites run these tests only in the disposable privileged job.

Gateway's default `phpunit.xml` excludes that group, so full and focused ordinary runs leave it out. PHPUnit's explicit `--group=privileged` overrides the XML exclusion in the privileged job and deliberate disposable-VM runs. The ordinary shard coverage check uses the same selection as its test command.

On macOS, `LinuxHost::delegate()` keeps the selected case's privilege group when it runs that case on `ORBIT_LINUX_TEST_HOST`. Ordinary delegation keeps the default exclusion. A deliberately selected privileged case adds `--group=privileged` for the child and needs a disposable Linux host with the case's privileged prerequisites. Delegation still requires exactly one passed case.

Keep one real privileged case for each behaviour where privilege is the point. When privilege is incidental, fake the process boundary and keep the behaviour assertions in the ordinary suite. Ordinary tests must pass with no sudo access and make no sudo calls, including capability probes and cleanup.

Before ordinary Gateway checks or tests, after system setup, the shared `.github/actions/no-sudo` action denies sudo to the user on a GitHub-hosted runner and fails on any runner where `sudo -n true` still succeeds. On a self-hosted runner it checks without changing host policy. This applies to the project job on every event and all PR shards. Absolute paths, a pinned PATH, `NativeProcessRunner`, and `env -i` cannot regain sudo access.

Stock PHPUnit `Before` and `After` fixture hooks also put a denying `sudo` shim first on PATH and fail the case if it was called, even when the caller ignores its exit status. The hooks surround setup and cleanup too. Child programs inherit the shim; fixtures that pin PATH use `TestToolchain::path()`.

The shared Python worker passes the current test's guarded PATH to each fresh child, rather than reusing the PATH from worker startup. This shim is a fast net, not a security boundary: it does not catch absolute `/usr/bin/sudo` calls or hand-pinned PATHs that omit it. The runner check that ordinary runners have no sudo is the boundary. A fixture that models a remote root boundary may fake that boundary, but must never forward it to host sudo.

Composer supervisor and check-runner fixtures set `COMPOSER_ALLOW_SUPERUSER=1` so Composer does not run its unrelated `sudo -K` credential cleanup. The check-runner fixture sets it in its account's login profile, which loads after the real check program filters its environment, including for authenticated setup and nested Composer scripts. The guard regression runs failing fixture cases through Pest to prove that a new sudo call fails the suite.

The privileged group runs in one job on a disposable GitHub-hosted runner with passwordless sudo. It runs the whole group with all detected CPUs, `--group=privileged`, and `--fail-on-empty-test-suite`. `Required checks` requires this job to pass.

The privileged job installs the recipe PHP build, Composer dependencies, ACL tools, and the `caddy` Unix account. It does not install Homebrew, Caddy, OpenSSL, WireGuard tools, or Node. The Valkey tests keep `ORBIT_TEST_DOCKER=1`; Docker pulls their image when a test first starts a container.

Stock Pest reads the committed `apps/gateway/tests/.pest/shards.json` for time balancing. This file stores class timings, not a shard count or fixed assignments. Stock Pest rebalances those timings for the requested count, so changing capacity needs no new timing recording or custom plan generator. The union check runs the stock listings for every configured shard, including newly added classes. `Required checks` requires the whole shard matrix to succeed, whatever its size.

Refresh the timings with a real full run on a disposable Linux machine with the privileged prerequisites. In `apps/gateway`, copy `phpunit.xml` to `phpunit.shard-refresh.xml` and remove its `<groups>` element so the recording includes both ordinary and privileged cases. Run `ORBIT_TEST_DOCKER=1 php -d auto_prepend_file=tests/bootstrap.php vendor/bin/pest --configuration=phpunit.shard-refresh.xml --parallel --update-shards`, remove the temporary configuration, and commit the changed timings in a PR.

Loading the test bootstrap first gives Pest's parent and workers the same temporary directory for timing files. CI never restores this file from Actions cache or writes it back. All shards therefore read the same timings from the tested commit, including on a partial rerun. Pest distributes new classes even before timings are refreshed.

The Gateway quality job runs `bin/check-pest-shards`. It uses Pest's own `--list-tests` output for the full non-privileged suite and each configured stock shard. It requires their union to equal the full listing and rejects duplicate assignments. A class that lands in no shard fails CI. Architecture tests are part of this listing too.

Every CI run shards the Gateway suite the same way: pull requests, handoff pushes, pushes to main, nightly runs, and manual runs. The privileged job runs on every CI event too. No run needs the whole non-privileged suite in one job.

### Self-hosted Gateway runner

When the repository variable `ORBIT_SABRE_RUNNER` is `true`, the Gateway job and the Gateway shards run on self-hosted runners. On every event, the Gateway quality job and each shard take any runner with the labels `self-hosted` and `orbit-shard`, Sabre included. No job selects a runner by the `sabre` label. The self-hosted runners are isolated [containers](#self-hosted-shard-runners) with 6 CPUs and 12 GiB each, and each runner runs one job at a time. The Gateway privileged job always runs on a GitHub-hosted runner.

Pushes, manual dispatches, and pull requests from branches in this repository use them. A pull request from a fork always uses GitHub-hosted runners, so code from outside the repository never runs on a self-hosted runner. Set the variable to anything else to move these jobs back to GitHub-hosted runners.

On a self-hosted runner, the job skips host package installation and only [verifies](#one-php-build-in-ci) the recipe's PHP build. The runner software is installed inside each container while it is idle. Each container uses Composer 2.10 and the PHP CLI defaults. Pest detects the container's CPU count without a worker override.

Sabre runs Incus runner containers. Each container's `runner` user has no sudo access, as required by [the Gateway test rule](#gateway-tests-and-privilege).

Each runner service mounts its own work directory at `/home/runner/work`, the path that GitHub-hosted runners use. PHPStan and Rector key their caches on absolute paths, so the caches saved by either kind of runner stay valid on the other.

### Self-hosted shard runners

The Gateway shards use the `self-hosted` and `orbit-shard` labels. The eight runner containers are `ci-beast-1`, `ci-shark-1` to `ci-shark-4`, and `ci-sabre-1` to `ci-sabre-3`. They serve the whole organization's queue, so the Gateway shard matrix has five shards: an Orbit run leaves runners free for other repositories' jobs. The workflow selects runners by label, not by name.

The [CI runner role](/reference/node-provisioning#ci-runner-role) owns the host project, bridge, ACL, profile, and managed routed firewall rule. The script owns container creation and organization registration; it has no `host` step.

Each runner carries `hardimpact,orbit-shard`, plus `sabre` on Sabre, alongside the default `self-hosted,Linux,X64` labels. Role adoption preserves every container and its running state; it does not require a fixed count. The workflow selects runners by label, not by name.

Each runner runs inside an unprivileged Incus container. Beast can share CI with app and database roles; Shark needs only the CI role. [`tools/ci-runners/setup.sh`](https://github.com/hardimpactdev/orbit/blob/main/tools/ci-runners/setup.sh) owns these idempotent container operations:

- `container NAME` launches the container, sets its `raw.apparmor` line, and installs what is missing.
- `register NAME [TOKEN]` registers the runner with the labels `self-hosted`, `Linux`, `X64`, `hardimpact`, and `orbit-shard`, plus `sabre` for a `ci-sabre-*` container.
- `verify NAME` probes, as the runner user, the host addresses and ports that a job must not reach.

`register` uses runner group `Default` at `https://github.com/hardimpactdev` and starts the runner service. It configures only a runner that has no registration yet. So it never changes the labels of a registered runner.

`container` upgrades no installed package, except the PHP build, which it brings to the recipe. Run it only while the runner is idle, because a repaired `raw.apparmor` line applies at the next restart.

Create an organization registration token with `gh api -X POST orgs/hardimpactdev/actions/runners/registration-token --jq .token`. The registration token comes from `ORBIT_RUNNER_TOKEN` or the argument, and the script never stores or prints it. `config.sh` takes the token as an argument, so the token shows in the host's process list for a few seconds.

The project is restricted: containers stay unprivileged, nesting is blocked, virtual machines are refused, and the only network is `orbitci0`. The role's default profile specifies 6 CPUs and 12 GiB. Each runner runs one job at a time. The process limit is 8192, with no devices beyond the network card and root disk.

Ubuntu 26.04's systemd hangs at boot in an unprivileged container without nesting. AppArmor denies its PID 1 and udevd the signals and traces to their own processes, whose labels are stacked. So each container sets one `raw.apparmor` line: `signal peer=@{profile_name}//&*, ptrace peer=@{profile_name}//&*,`. It allows signals and traces only to processes that carry the container's own profile, so it reaches nothing outside the container. Nesting stays off. The project allows container low-level keys only for this line; only `incus-admin` members can set them.

The ACL rejects every egress to private, CGNAT, loopback, link-local, multicast, and reserved IPv4 ranges, and all IPv6. Its default for egress and ingress is reject. So a job reaches the public internet, but not the host's LAN, WireGuard, the Gateway, docker and task bridges, the other container, or the bridge gateway. A container's `127.0.0.1` is its own loopback, so the host's loopback services, such as the Caddy admin API, are out of reach.

Inside each container, the `runner` user runs the runner service, with no `sudo` and no group beyond its own. So a shard leaves out the `privileged` group. The container has the recipe's [PHP build](#one-php-build-in-ci), installed by `bin/php-build install --profile=ci-runner`, Composer 2, Caddy from its own apt source with its service disabled, `acl`, `attr`, `wireguard-tools`, `dig`, Git, and Python 3. `container NAME` copies `bin/php-build`, the recipe, and the remote program tree that `bin/php-build` reads into the container, and runs that install. A later run replaces the copy and updates the build in place. Composer is at `/usr/local/bin/composer`, with a link at `/usr/bin/composer`, because the Composer update program and its tests run that path, as on a Node.

Each container also has Ubuntu's `postgresql-18` and `postgresql-client-18` packages. Setup installs `postgresql-common` first and sets `create_main_cluster = false` in `/etc/postgresql-common/createcluster.conf` before installing the server. Every run keeps automatic cluster creation off and disables and stops the `postgresql` service. Jobs use `/usr/lib/postgresql/18/bin/initdb` and `pg_ctl` as `runner` to start a throwaway cluster on a Unix socket. Docker service containers need nesting, which is blocked, and egress rules block the Services Node's PostgreSQL.

Database server API tests fake the admin command boundary, including repair and removal. They check HTTP responses, command intent and recorded state without starting PostgreSQL or running psql. Native SQL regressions live in the database-server infrastructure tests. These retain real Laravel worker probes, interrupted CREATE/grant recovery, PostgreSQL privileges and removal fences.

Gateway's PostgreSQL tests initialize one private seed per test worker and copy it for each disposable PostgreSQL 18 cluster. The seed is never started and is removed when the worker exits. Each test starts its own cluster on a unique Unix socket, with no TCP listener, and removes it afterward. This avoids repeated `initdb` work while keeping database state isolated. Hosted Gateway jobs install `postgresql-18`. CI fails rather than skipping if the binaries are absent. Local runs may set `ORBIT_POSTGRES18_BIN` to a PostgreSQL 18 bin directory; otherwise the test skips when `/usr/lib/postgresql/18/bin/initdb` is unavailable.

The job installs Node 24 with the Node setup step, and each shard uses all detected container CPUs. The container's PHP CLI keeps `zend.exception_ignore_args` on, so the job turns it off only for its own processes: it writes an ini file into the runner's temporary directory and adds it to `PHP_INI_SCAN_DIR`.

Six Gateway workers fit each 12 GiB runner limit using the [measured worker allocation](#the-gate-checks-changed-files): 6 × 1664 MiB plus the 640 MiB controller reserve is 10.375 GiB, leaving 1.625 GiB for the runner service and other overhead. The observed heaviest Gateway worker was 1506.1 MiB. This is a capacity estimate from that full-suite measurement, not a new six-worker peak measurement. Each container runs one job, so no other CI job shares that memory budget.

Two limits remain:

- The runners are not ephemeral. A job can leave files or processes in its container for the next job. The container is the boundary, and only approved code from this repository runs there.
- Incus accepts DNS on port 53 from `orbitci0` to every host address, before the ACL applies. Closing it needs a host firewall rule.

So a job can query each dnsmasq on the host, such as the task bridge's. The answers reveal task names and addresses that the job still cannot reach, and a query changes nothing.

To remove runners, stop and uninstall each runner service as root in its container, then run `config.sh remove` as `runner` with an organization removal token. Delete the containers with `incus delete NAME --project orbit-ci`. Clear cached images with `incus image list --project orbit-ci -f csv -c f | xargs -r -n1 incus image delete --project orbit-ci`, and remove custom volumes and extra profiles. Then use `orbit node:role:remove <node> ci-runner --force`, with `--purge-data` to remove the empty project, bridge, and ACL. The role owns the routed firewall rule. It never deletes a runner or registration.

Docs-lint also checks the ADR lifecycle. A row in the decisions overview's lower table has no file with its recorded slug, and a redirect exists from that exact path. ADRs from 0180 onward have an `In progress.` Status and a `Principle:` line. A lower number follows those rules only when that lower table does not list its number. The open gaps are 0007 and 0020. The committed allowlist of older live ADRs can only shrink. The [contributor guide](/contributor-guide#checks-that-need-no-network) explains these checks.

Dependency downloads use stock `actions/cache`. The shared PHP setup caches only Composer's download directory, keyed by runner OS, architecture, Composer version, and all Composer lockfiles. Bun jobs cache only `~/.bun/install/cache`, keyed by OS, architecture, Bun version, and all Bun lockfiles. Only non-PR runs on `refs/heads/main` write these entries; all other runs restore without saving. Installs still run with the lockfile in each job. Jobs never share installed `vendor/` or `node_modules/` trees, and Playwright browsers are not cached.

Each Composer project job also caches two sets of quality files in GitHub Actions cache.

| Cache | Key inputs |
| --- | --- |
| PHPStan result cache, `vendor/phpstan/cache/resultCache.php` | `composer.lock`, `phpstan.neon` |
| Pint and Rector caches, `vendor/pint.cache` and `vendor/rector/cache` | `composer.lock`, `pint.json`, `rector.php` |

A run on main restores quality caches for its branch, then the project. A PR restores quality caches only from main and saves none. Runs outside PRs save quality caches only after passing. Shard timings are committed source, not a cache.

A passing project job on `main` also stages its Pint and PHPStan caches as a [CI artifact](#ci-artifacts) for the [SHA-bound Git ref](#ci-cache-refs) that task workspaces import.

The separate `Orbit CLI Binary` workflow builds the toolbox binaries on pull requests. It is not part of `Required checks`. After a `CI` run on `main` passes, the `Orbit CLI Release` workflow publishes that commit's binaries as a GitHub release. See [CLI binaries](/reference/cli-binaries).

## Local checks

In an Orbit task, run the tests that cover the change in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Use stock Pest with a test file path or `--filter`. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff, and CI runs the full suites on the handoff commit. Outside a task, run the full suite and the checks below. Each project keeps its own dependencies and test configuration.

| Command | Result |
| --- | --- |
| `vendor/bin/pest --compact PATH` or `--filter=NAME` | Runs the tests that cover a change |
| `composer check` | Runs `guidance:check`, PHPStan, Rector, and the Pint check. It does not run the full Pest suite. |
| `composer test` | Runs the project's full suite in parallel processes |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

In `apps/docs`, `composer check` also runs the documentation lint, the API fixture check, and the external generated-merge proof. `guidance:check` runs the project's guidance tests. In every project, `bin/guidance-check` creates a private temporary directory for Blade views before Pest starts, then removes it when the command exits. The gate does not depend on `storage/framework/views` existing or on caches written by another user. The check still fails if Boost cannot render guidance or changes the markers required by the hard-stop transformation. The [contributor guide](/contributor-guide#static-analysis) sets the PHPStan level and the rules for fixing findings.

Confirm that the tests of the feature ran. A filter that matches no test is not acceptance evidence.

Each project requires stock `pestphp/pest` from its lock file. Stock Pest includes test impact analysis, which turns on with `--tia` or `--locally`, a `PEST_TIA` environment variable, or a `tia()` configuration. Orbit uses none of them, and a contract test checks the project scripts, the PHPUnit configuration, the workflows, the CI actions, and the `bin` scripts. To adopt a new release, update the dependency and the lock file in every project, then run the project checks.

Stock ParaTest detects the available runner CPUs for full Docs runs in CI, which use `pest --parallel` and record no graph. Every Pest case remains subject to the two-second limit. The external generated-merge proof runs sequentially in the quality step, separate from ParaTest; see the [fixture guidance](/contributor-guide#3-implement-and-verify).

Root `bin/test` queues the five project suites through the same weighted lane allocator as the candidate gate. The total of concurrent Pest workers stays within the [available process budget](#parallel-processes), including when the budget is smaller than the number of suites. Each finished suite frees its share for the remaining suites.

The E2E tests for gate selection build one Git repository per file, with two commits. Each test gets an independent copy. Cases that need a different history change only their copy. The gate-selection fixtures make Pest fail if the local gate tries to run a test suite; handoff suites run in CI.

Gateway feature tests use Laravel's `LazilyRefreshDatabase`: the first query or transaction triggers the same real migrations and transaction setup. A test that inspects its ambient transaction level first issues a query to establish the test transaction. Guidance rendering does not use a database, so it pays no migration cost.

The Gateway schema baseline includes database private-endpoint ownership, target networks and connection locale. It records the squashed migration names and batches without choosing their row IDs, including the October 21 and 22 database migrations. Earlier vendor migrations can occupy IDs first. The migration that seeds reference rows preserves existing configuration; its rollback leaves those rows in place. Documentation `covers` names the baseline file, not the whole migrations directory. New migrations belong to the pages that own their behaviour.

The E2E checks for unusable caches and inherited databases run the Gateway's real renderer for strict guidance. They do not repeat its unrelated guidance tests. The inherited-database check also runs the real feature database test: its first query triggers migrations, and the caller's sentinel database must remain unchanged.

Gateway smoke cases use a fake Gateway and real subprocess and HTTP deadlines. Each Python unittest case runs separately in Pest. Polling tests set a 0.25-second interval in the test process; the production poll interval and 20-second command limit stay unchanged. The run's total budget also caps each command. Timeout cases still expire real deadlines, including the total run limit. The no-tick and total-timeout tests keep their four- and three-second total limits so concurrent scheduler, agent-view, and CLI checks have time to finish under load.

The slow-Orbit case runs the real smoke result aggregation and tick check with a one-second total budget and real CLI subprocesses. Unrelated read checks use immediate successful doubles, each asserted to run once; their real behavior has separate coverage. The first tick read returns a stale tick immediately, and the second stalls past the total limit. The test keeps the JSON, exit-status, read-count, and last-seen-tick assertions without requiring unrelated subprocesses to finish within that second. A shorter poll interval does not reserve time for the next read.

The worker-budget test keeps a locked count of active shares, including overlap between waves. It lets the first lane acquire its share before later lanes register, so later allocations must respect shares already held. Signals report when each active lane has either acquired a share or entered the allocator's real condition wait. Workers stay on sockets until all granted workers in that wave have connected; no observation sleep determines completion.

### Parallel processes

Pest, PHPStan, Rector, and Pint use one process rule in `bin/check-processes`. At check start it reserves 640 MiB for gate overhead, then divides the remaining available memory by the measured allocation of 1664 MiB per worker, takes the smaller of that count and the available CPU count, and allows at least one worker.

On Linux it reads CPU affinity and cgroup v2 CPU quotas. Available memory is the smaller of `/proc/meminfo`'s `MemAvailable` and each bounded cgroup's limit minus its working set, including ancestor bounds. The working set is `memory.current` minus `memory.stat`'s reclaimable `inactive_file` pages. On macOS it reads the CPU count and the free, inactive, and speculative pages from `vm_stat`. Unreadable resources fail the check.

The floor of one can overcommit memory and OOM when less than the reserve plus one worker is available. The rule assumes one gate per task VM and does not reserve resources between concurrent gates on a shared host. CPU is schedulable capacity, not idle capacity: topology processes share it and can slow the gate.

| Tool | How it reads the process count |
| --- | --- |
| Pest | `--processes=$(../../bin/check-processes)` in the `test` script |
| Pint | `--parallel --max-processes=$(../../bin/check-processes)` in the `format:check` script |
| PHPStan | `tools/phpstan/parallel.php`, which every `phpstan.neon` includes |
| Rector | `->withParallel()` in `rector.php`, with the value from `tools/check-processes.php` |

Each parallel Rector worker keeps its cache in its own directory under `vendor/rector/cache/workers/`. `tools/rector-cache.php` names the directory and removes it when the worker exits. The next run's `--clear-cache` removes a directory that a killed worker left. Rector writes a cache file with a plain copy, and every worker rewrites the same configuration entry when it starts. Workers that share a directory can read a half-written file and fail with a syntax error.

Gateway's `RunScheduledCommand` keeps an explicit default for its command arguments so jobs queued before that property existed can still deserialize. Its Rector configuration excludes that class from constructor promotion, which would lose the property default on those payloads. A queue regression test covers this deployment boundary.

The PHP helper `tools/check-processes.php` invokes `bin/check-processes`; it contains no allocation rule. The candidate gate and `bin/test` use `tools/check_budget.py` to split the resource-derived budget between their lanes and suites with the internal `ORBIT_CHECK_PROCESSES` variable. A positive integer lane share is used verbatim, and an invalid share fails the check. Do not set it on a Project or in an operator shell. CI test commands bypass this local gate budget. Stock ParaTest detects all runner CPUs, as [Gateway shards](#gateway-shards) describes. CI quality tools still use this helper.

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

When the candidate changes `apps/gateway/resources`, `apps/gateway/tests/Programs`, `apps/gateway/ruff.toml`, or `bin/remote-programs-check`, the gate also runs `bin/remote-programs-check`. See [Remote programs](/reference/remote-programs#lint-and-direct-tests).

For each Composer project, the gate runs `composer validate --strict` and `composer check`. Its `composer check` runs Pint and Rector only on the files that the candidate affects. [Changed-file quality checks](#changed-file-quality-checks) lists them.

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

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The build also requires nonempty `dist/index.html` and `dist/version.json`, as CI does. The generated-types check runs `openapi-typescript` from the web app’s `tools/api-types` workspace and writes its output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. On failure it prints a unified diff and says to run `cd apps/web && bun run types`. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, and `bun run build` there. Its tests run in CI. Rust formatting runs for changed Rust projects; the remaining Rust checks run only in CI.

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

`bin/bootstrap` installs all five Composer projects, [seeds the quality caches](#seed-a-checkout), and validates each project's guidance. Then it runs `composer test`, the project's full suite, and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the guidance validation. `bin/bootstrap --dependencies-only` stops after dependency installation, including any requested Bun and Rust dependencies; it prepares no development state and runs no checks.

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It removes the worktree. Then it deletes the merged branch. It releases no topology: a topology runs only in a task VM and goes with it.

## Main caches

Main caches let a new checkout start with warm Pint and PHPStan result caches from `main`. Task VM workspaces import them from [CI cache refs](#ci-cache-refs). A local checkout copies them from the primary checkout, as [Seed a checkout](#seed-a-checkout) describes.

### CI cache refs

The `publish-ci-cache` job in `ci.yml` publishes main's Pint and PHPStan caches for task workspaces after `Required checks` succeeds on `main`. It runs for a main push, scheduled run, or manual dispatch on main, never for a pull request, fork, or other branch. It gathers the five Composer projects' [quality exports](#ci-artifacts) from the same run and tested SHA. All project jobs must pass before publication, because the job runs only after `Required checks` succeeds. A failed, cancelled, or incomplete run publishes no ref.

Only this job receives `permissions: contents: write`. It pushes to the repository with its short-lived `GITHUB_TOKEN`; no personal token or Gateway credential is stored in CI. The workflow's other jobs keep `contents: read`. The Gateway later fetches the ref with its GitHub App's repository-scoped `Contents: read` token, through the same protected Git path as source reads. Neither a task VM nor an agent receives either credential.

The job runs `bin/ci-cache-ref publish` to write a parentless cache-only Git commit and push it to `refs/orbit/ci-cache/<sha>`. Its tree contains no source files or source history. The suffix is the full tested code SHA, not the cache commit's SHA. The tree groups payloads by repository-relative project path.

Each project has a schema-3 manifest and optional Pint and PHPStan caches. The manifest records the tested code SHA, project, CI run and exporting attempt, the checksum of each cache, each tool's configuration checksum, and the PHP minor version. It holds no test graph. A partial re-run may keep exports from earlier successful attempts of the same run and SHA. [Rector runs without a cache](#ci-caches-stay-separate); this transport does not change that policy.

Before pushing, the publisher verifies that every manifest names the run's tested SHA, that each tool configuration matches that commit, and that all five project exports are complete. Consumers repeat the SHA and checksum checks; a ref name alone is not evidence that the payload fits. The source of trust is the successful main workflow and repository write access, not the checksums. PHPStan caches contain executable PHP.

A ref is immutable while retained. A retry at the same code SHA keeps an existing valid publication rather than force-pushing it; a conflicting publication fails the publishing job. Publication failure leaves the code available without a cache ref and records the failure in the publishing job log.

A new task VM workspace starts at the `origin/<default branch>` tip. For a Project that declares `@orbit/ci-caches`, the Gateway independently selects the newest commit on that tip's first-parent history with a published `refs/orbit/ci-cache/<sha>` ref, records it in `refs/worktree/orbit/ci-baseline`, and imports its caches. It logs the baseline SHA and its distance from the tip, including zero for a cached tip.

If no ancestor has a cache ref, the workspace starts cold with no recorded cache baseline. If the optional ref fetch fails, the Gateway discards the mirror's cache refs and logs the fetch failure as the cause of the cold start. Neither case fails the workspace claim. It does not wait or poll for publication, or try another baseline if the selected cache is invalid. A baseline retry moves the untouched workspace to the current default branch tip and selects its cache baseline by the same rule.

Existing task branches, seeded worktrees, and incoming pull requests keep their selected source commit. The ref for the remote default branch still tracks its latest head. The PR must pass the normal full-suite gate against latest main before merging.

The Gateway fetches the `refs/orbit/ci-cache/*` namespace into its mirror alongside source refs. Cache fetching is optional: a cache-ref miss must not fail the required source fetch. The Gateway sends only the ref for the selected code baseline to a workspace over SSH. [Instance setup](/reference/instance-setup#import-ci-main-caches) owns the compatibility checks and import. A missing or incompatible payload leaves checks cold at the selected code commit. A cache ref for another SHA, even a newer one, is never substituted. The workspace imports Git objects, not an Actions download or a base64 command payload.

### Cache ref retention

After each publication, the publisher prunes `refs/orbit/ci-cache/*` to one ref: the ref of the newest tested main commit. Main commit ancestry, not job completion order, identifies that commit. So origin holds only the newest publication, and a workspace never selects an older cache baseline. Pruning never touches source branches, tags, or other refs.

The Gateway removes upstream-deleted refs only from its mirror's cache namespace. It does not delete an active workspace's local cache objects or private tool caches. A workspace whose baseline ref was pruned starts cold. Git garbage collection can reclaim unreferenced cache objects later.

### CI artifacts

After a project job passes on `main`, CI uploads the artifact `ci-cache-<index>-<commit>` and keeps it for 14 days. Pull request runs upload none. These artifacts stage the exports for `publish-ci-cache`. They are not a workspace transport: the Gateway reads Git refs for `@orbit/ci-caches`, without downloading artifacts or putting their contents in a setup command.

| File | Content |
| --- | --- |
| `manifest.json` | Schema version 3, project path, tested commit, CI run and exporting attempt, PHP minor version, and the checksum and configuration checksum of each quality cache |
| `quality/pint`, `quality/phpstan` | The Pint and PHPStan result caches that `composer check` wrote in the same job, when it wrote them |

### Seed a checkout

`bin/worktree-cache` copies Pint and PHPStan caches into a checkout's absent private caches and never replaces an existing one. Bootstrap and the candidate gate run it. Its only source is the primary checkout, and only while that checkout is clean on `main` and has the same `composer.lock` and tool configuration as the project. Feature worktrees are never a source. A missing or incompatible cache leaves that project cold, and seeding does not fail.

A task VM workspace [imports a CI cache ref](/reference/instance-setup#import-ci-main-caches) instead. That import also checks the runtime facts that each tool compares. A Project setup step can reflink each dependency tree and private tool cache from `ORBIT_SEED_PATH` first.

### Failures on main

A failure on main is a `Required checks` check run on a `main` commit that did not succeed. Read it for the current tip with `gh api 'repos/hardimpactdev/orbit/commits/main/check-runs?check_name=Required%20checks'`. Diagnose it on the failed commit. Fix it, or revert the change that caused it, through a reviewed pull request. The next `main` commit whose `Required checks` passes clears it.

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

A feature checkout can hold unmerged code or working edits, so its tool caches cannot stand for main. Only main CI publishes CI cache refs, and `bin/worktree-cache` copies only from a clean primary checkout on `main`. Each checkout writes to its own private copy. One writable cache shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results.

### Only main CI publishes

CI already checks every `main` commit, so its quality caches cost nothing extra. A deployment never runs a test suite.

Running the checks on a development machine to fill a cache is a rejected alternative. It repeated the work of CI, competed with other agents for the processors, and inside a deployment it exceeded the deployment's deadline. Its results also depended on the host: a test that passes in CI failed on a host with other file ACLs, and that failure held merges as a correctness failure on main. Publishing the caches of a clean bootstrap on `main` is rejected for the same reason, and because two producers would race.

### Main runs record full suites

Main always runs full suites, sharded as on a pull request. This keeps merge and release evidence independent of test selection. A main affected-test selector and separate subprocess-only lanes are rejected: they add selection rules without proving every test passed. Reusing a PR result is also rejected because main can differ from the tested PR commit.

### Full suites gate merges

Nick decided on 2026-10-19 that PR `Required checks` run the full test suite of every touched project on the merge ref, including architecture tests and tests that start PHP subprocesses. On 2026-10-21 he confirmed that untouched projects must start no CI jobs. The `changes` job compares the PR head with its merge base and applies the path and consumer map in [`bin/ci-changes`](https://github.com/hardimpactdev/orbit/blob/main/bin/ci-changes). That file owns project paths, shared contracts, and their consumers.

Root Composer files, workflow and action files, `bin/`, `tools/`, shared configuration, and unknown paths select everything. Ordinary documentation pages select only Docs; API documentation also selects API reference checks, and generated API contracts, CLI guides, and the delivery-line reference select their tested consumers.

Each selected project runs its full suite. Job conditions skip untouched projects before they take a runner, and the Composer matrix contains only selected projects. `Required checks` accepts a skipped job only when `changes` succeeded and explicitly marked that job untouched. A failure, cancellation, missing selection, or unexpected skip fails the gate. Main pushes, schedules, and manual dispatches select every project, so main checks the dependency map within one merge.

Review and merge with `merge_check: Required checks` waits for that check on the reviewed head. Only the pull request's own `pull_request` run for that head counts, also when the head is the last handoff commit, so the merge with the current base is tested before it lands. GitHub’s merge API still checks the expected head. Main pushes, schedules and dispatches run the same full suites.

The [declaration-data audit](/solutions/test-impact-declaration-data) found that enum cases, constants, property and parameter defaults, and attribute arguments can change behavior without per-test coverage edges for later readers. Its seven-case fixture produced a false green from coverage-based selection while the full suite failed four cases. A general declaration selector would widen 496 of 580 entries on main’s first-parent history that changed app code (85.5%, about 86%). That selector is rejected.

Nick decided on 2026-10-20 that each task handoff runs the full suites in sharded CI on the pushed handoff commit, as [Tasks: Handoff CI](/reference/tasks#handoff-ci) describes. The task VM keeps only the fast checks. So no handoff, rework, or fixup depends on a test selection. Running the full suites in the task VM is a rejected alternative: there the Gateway suite alone took 2614 seconds on one worker, and CI shards it.

Nick decided on 2026-10-20 that Orbit runs no test impact analysis. Once its selection was sound, it chose a median of the whole suite, so it saved no time, and recording coverage added about 19% to test time. Implementers run the tests that cover their change with stock Pest, and CI runs the full suites. Keeping a selector for the task loop is a rejected alternative.

Gateway uses stock Pest time-balanced shards to keep every full run short, on main as on a pull request. Committed timings keep all shard jobs on one version without a planner or cache race. Quality checks overlap the shards.

### Gateway test time limits

Gateway uses stock PHPUnit per-test limits: `enforceTimeLimit="true"`, `defaultTimeLimit="2"`, `failOnRisky="true"`, and `stopOnRisky="true"` in `apps/gateway/phpunit.xml`. The default is two seconds locally. Tests remain unsized except for reviewed cases whose real deadline or kernel age is the behaviour under test.

Every direct Pest invocation in `ci.yml` passes stock `--default-time-limit=5`, including project full suites, the PCRE JIT-disabled redaction run, Gateway shards, and privileged tests. `composer check` also runs Pest through `guidance:check`. Its shared launcher adds the same stock flag when `CI=true`; local guidance runs keep their existing arguments and configuration. Unsized tests therefore get five seconds in CI without changing local limits.

CI permits five seconds because CPU contention slows work on shared hosts. Aborts at two seconds across changing tests, including pure renderers, are not evidence that those tests need rewriting. Investigate any failure that remains at five seconds as a real bug. There is no suite-time target, CI-step timing gate, or headroom requirement. Do not add per-test CI budgets or timing machinery.

An exception uses a dedicated native PHPUnit class with class-level `#[Large]` and a one-line justification beside the attribute. Each case needs review; the class contains only the approved real-deadline cases, never unrelated tests. The approved classes are `DocumentTransportDeadlineTest`, with the native Guzzle connection and total-request deadlines, and `LinuxHostCtimeTest`, with real kernel-ctime aging through GNU `find`. They use stock PHPUnit `timeoutForLargeTests`, without an override. Installed PHPUnit supports sizes only on classes, and installed Pest cannot declare sizes. Do not substitute size-named groups or add custom timeout or sizing machinery.

Fix slow cases at their cause: control clocks and transports, share expensive read-only setup, and keep a real subprocess or deadline only when it is the proof. Preserve every behaviour assertion. The WebSocket pong-write deadline proof keeps the real TLS connection and zero-byte writes. Its controlled application clock checks the deadline boundary and expiry without waiting five seconds. Finite local fixture commands may use `Tests\Support\FixtureProcessRunner`, which waits on pipes instead of polling for production cancellation. It rejects streaming and cancellation callbacks; those proofs use the native runner.

Native PHPUnit Feature tests extend `Tests\FeatureTestCase` with the same database and cache traits as Pest's Feature cases. Both use `FeatureTestEnvironment` for storage and process isolation, transport guards, and cleanup. Extension-specific setup remains with its tests.

Read-only fixtures are prepared once per file or worker, then copied into private writable directories. Gateway release fixtures share the initial Git origin and adopted release; each case still owns its working repositories, database, runtime and cleanup. Cleanup restores directory access in the PHP process before descending, without following symlinks or changing regular-file modes, then removes the private tree. It does not start a `chmod` process for every directory.

The fixture that snapshots a live WAL shares only its initial database, not the writer, snapshot, transfer or import. It keeps all 200 rows and the 64 MiB BLOB. The real writer runs without a delay.

The Documents scheduler proves startup and recurring cleanup, explicit pause/resume, dead-worker fencing, and daemon restart in separate cases. Each case owns its database, HTTP server and workers, with real `schedule:work` dispatch and `schedule:run` children. A restart case may prepare earlier deletions through the real cleanup action; the old worker's child dispatch has its own proof.

ACL race fixtures cover ten real walks in five independent pairs. Each pair owns its checkouts and releases both walks at each find/setfacl boundary before waiting for the next boundary. Every pair checks removals, replacements, late-file inheritance and source denial. File reads under the Caddy identity use one process per checkout.

Valkey fixtures prepare an immutable ACL seed once through the real remote writer. Each case checks that seed against its records and copies it only at first boot, before executing the production image's entrypoint and server command. Restarts never restore the seed; missing-ACL failure and repair still exercise the real writer. Fixtures acknowledge each boot directly from the attached Docker process and do not persist unrelated Docker logs. Mutable servers and volumes remain private to each case.

Workspace TMPDIR proofs acknowledge readiness and hold checks open through private FIFOs and shell builtins, not marker polling or `sleep` children. Cancellation still signals the real check process group. The fixture does not add a disposable sleeper whose unreaped process can hold that group open during cancellation. Setup, checks and deliverables still run in two independent workspaces with real permissions and ACLs. Each phase records its TMPDIR. Capture the final permission evidence once per workspace rather than overwriting it in each phase.

Copy the initial committed Git repository into each private checkout. The analysis stand-in uses shell builtins for its temporary-file text writes and reads. Status and snapshot probes have separate private cases, each retaining the TMPDIR identity, mode and child ACL checks before real cancellation.

A scheduler proof with fixed collaborators reuses its real scheduler graph within one case, including successive handoffs, CI-state reads and failure-budget ticks. Resolve a fresh graph after rebinding a collaborator. The fixture shares the immutable subtask runner only while building one graph and preserves caller-owned bindings. It does not cache the scheduler across cases. Freeze the clock before starting a handoff age-boundary proof so elapsed test execution cannot cross the boundary before an explicit time advance.

Task VM source fixtures use the same local executor for finite Bash programs. Each case owns its repositories, cache publications and imports. Proofs of removal, cancellation and streamed output still run the actual processes.

Production deployment fixtures copy committed read-only Git seeds into private writable repositories instead of rebuilding the same history for each retained release. Each case still runs the real release programs, ACL changes and filesystem checks. Buffer finite Bash programs before execution so the shell does not read each source line from a process pipe.

Source the production deployment fake `sudo` fixture in a Bash function instead of starting `env` and another Bash for every command. The fixture only shifts its own arguments and invokes the command, so Bash builtins such as `test` run without another process. Real Git, filesystem and ACL programs still run, and the function preserves command arguments and exit status. Other scripted command fixtures keep their isolated subshell when they change variables or shell options.

The private-SSH-home permission proof keeps real `sudo` and the Caddy identity. A busy release worker acknowledges its working directory through its output pipe and waits on shell input; readiness does not poll `/proc` or depend on a sleeper.

Private DNS publication fixtures source their scripted service, socket, sleep and PID-matching commands as shell functions. The PID matcher consumes the scripted socket output without starting `grep` on each retry; it handles the numeric PID query used by the publication program and delegates other queries, including release-retention file checks, to real `grep`. The restore proof still performs and checks all 100 failed socket probes and the real fragment rollback.

The shared Git cleanup regression runs stock Pest against a native fixture with real frozen release seeds and the class-cleanup trait. It does not boot a nested Laravel application merely to test class cleanup before the run directory is removed. The parent waits through PHP's `exec`, with captured output and an asserted exit code. It does not leave a Symfony pipe error handler installed while waiting for the child.

Python deadline fixtures block until the timeout kills them; they do not race a short sleep against a nearby deadline. A following immediate command proves that the shared host remains usable.

Fixtures for Python programs that use only the standard library can use `StandardLibraryPython` to omit host site-package startup. Arguments, binary stdin, environment, exit status and execution in a fresh process remain real. `LocalShellSshExecutor` buffers finite `bash -seu` stdin programs in private temporary files; other command arguments remain data. Protected inputs keep their caller-owned lifetime.

Selected authentication, SSR and repeated-check cases can inspect actual local check artifacts after the real check exits. Dedicated status and output transport cases still use the remote reader. Do not use local artifact helpers for streaming or cancellation proofs.

Timeouts fail the run. `stopOnRisky` prevents later cases in the same worker from consuming state left by interrupted cleanup; it does not guarantee cleanup completes. The Gateway PHP recipe requires `pcntl`, and the shared CI PHP action verifies the recipe before running suites. Keep that requirement in the recipe, not individual jobs.

Give datasets containing invalid UTF-8 readable names. This keeps JUnit test names valid without changing the input bytes that the test validates.

Process fixtures acknowledge readiness from their final executable before a case acts on them; a PID alone does not prove startup finished. Tests of leftover cleanup after `SIGKILL` first observe that the check is lost, then cancel it. Sending a signal does not mean the process has exited.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner. On `main` it uploads the quality exports that the [CI cache refs](#ci-cache-refs) carry. Pint and PHPStan key their caches on paths relative to the project, so their CI caches work in any checkout.

CI does not restore or save Rector's cache. Every project's `composer rector` uses `--clear-cache`, including runs through `composer check` in CI and the local review gate. Rector's unchanged-file cache does not track all class dependencies: a parent-only property change can leave an unchanged child's missing `#[Override]` undetected. Clearing the cache trades warm-run speed for a complete check of the configured Rector paths; Pint, PHPStan, and Pest keep their existing caches. The candidate gate narrows the files instead of keeping a cache, as [The gate checks changed files](#the-gate-checks-changed-files) explains.

The regression in `apps/docs/tests/Unit/ToolingBaselineRectorCacheTest.php` reads the project's Composer script in the test body and requires an exact `--clear-cache` argument. Removing the argument makes the test fail. `bin/tooling-baseline` keeps the Rector command identical in every project, and its drift test rejects removal of the argument from another project too.

Running Rector twice from cold adds unrelated work. The direct command assertion checks the setting Orbit owns instead of retesting Rector's cache implementation. No Rector work or assertions run in a class-level hook.

Gateway timings were measured in the task workspace on Linux x86_64, with four CPUs, PHP 8.5.11, and Rector 2.6.7. They were 126.24 seconds for an uncached run and 0.65 seconds for a warm run before the fix. After adding `--clear-cache`, `composer rector` took 154.77 seconds on the first run and 118.92 seconds on the repeat. These are wall-clock samples, not a performance guarantee: checks now pay the full analysis cost on every run.

### The gate checks changed files

A new Orbit Instance or task VM starts at a `main` commit that CI already checked. Before this change, `composer check` there took about 10 minutes, almost all of it in the quality tools. Rector ran in one process without a cache, and Pint ran in one process. In the Gateway, Rector took about 155 seconds and a cold Pint about 288 seconds.

The gate now checks with Pint only the files the candidate changes, because Pint judges each file on its own. Rector's findings in a file can depend on other files, such as a parent class. So Rector also checks every file that PHPStan records as depending on a changed file. PHPStan records these dependents for its own result cache, and the gate runs PHPStan first, so the record describes the files as they are. A record that does not match the files makes Rector check everything. The gate never checks less than it can prove covers the change.

CI checks every file. It tests the merge result of a pull request, and its result decides the merge. The gate gives fast feedback at each handoff.

All four tools run in parallel processes. The old limits of two PHPStan and Pest processes had no recorded reason. Rector ran in one process, and parallel workers that share its cache directory can fail on a half-written cache file, so each worker now has its own directory. With 16 processes on a 16-core machine, a full Rector run in the Gateway takes about 32 seconds with the same findings, and a cold Pint run about 37 seconds.

Memory and CPU quotas bound the process count. The gate shares the resource-derived budget between the projects it runs at once. The Gateway is the largest project, so it gets the largest share. Each lane passes its share to every tool through `ORBIT_CHECK_PROCESSES`.

A complete normal-VM gate on Beast on 2026-10-19 UTC exercised the Gateway workers. The gate still ran each project's selected tests then. Pi 0.87.1 stayed resident in idle RPC mode, with no model calls. The real diff against `353ef196c` selected all 847 Gateway test files from 104 changed files. The Gateway tests passed 13,954 tests with 21 skips and 108,537 assertions in 2614.290 seconds. Its heaviest worker reached 1506.1 MiB `VmHWM`. The final allocation is 1664 MiB, about 10.5% above that measured peak.

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

The task check runs no Gateway tests, so it needs neither host sudo nor another Unix identity. The required disposable Gateway privileged CI jobs run the `privileged` group; see [Gateway tests and privilege](#gateway-tests-and-privilege) for the rule. Check report directories permit group reads so the worker can inspect failures.

The shared installer inspects runner-writable JavaScript executables as `runner`, including the setup smoke check, which runs from `/` and compares the result with the recipe pin. Inspection passes `VP_HOME` after sudo in argv so sudo environment filtering cannot create another Vite+ store. All Node-consuming CI jobs, including the dependency audit, read `.node-version`. Recipe convergence owns Node.js on fleet Nodes and retires duplicate APT ownership after verifying the replacement and simulating dependency-safe removal.
